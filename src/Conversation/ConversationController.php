<?php

declare(strict_types=1);

namespace NeuronTui\Conversation;

use Amp\Future;
use Generator;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronChatCore\Conversation\ConversationRuntime;
use NeuronChatCore\Session\Session;
use NeuronTui\Session\SessionTitleGeneration;
use NeuronTui\View\ConversationView;
use NeuronTui\View\WorkingIndicator;
use Throwable;

use function Amp\async;
use function array_map;
use function array_shift;
use function microtime;

/** Terminal scheduling and presentation for the host's core runtime. @internal */
final class ConversationController
{
    private readonly WorkingIndicator $workingIndicator;

    /** @var Future<mixed>|null */
    private ?Future $runningTurn = null;

    private bool $stopped = false;

    private bool $preparingTurn = false;

    private readonly TurnRenderer $renderer;

    /** @var Generator<int, object, mixed, AgentState>|null */
    private ?Generator $readyStream = null;

    /** @var list<PendingMessage> */
    private array $pendingMessages = [];

    private ?string $displayedHistory = null;

    public function __construct(
        private readonly ConversationRuntime $runtime,
        private readonly ConversationView $view,
        private readonly ?SessionTitleGeneration $titleGeneration = null,
    ) {
        $this->workingIndicator = $view->workingIndicator();
        $this->renderer = new TurnRenderer($view);
    }

    public function submitUserMessage(UserMessage $message): void
    {
        $this->enqueue(new PendingMessage(clone $message, true));
    }

    /** Accept a command-generated prompt; its live preview uses display projection. */
    public function submitMessage(UserMessage $message): void
    {
        $this->enqueue(new PendingMessage(clone $message, false));
    }

    private function enqueue(PendingMessage $pending): void
    {
        $this->pendingMessages[] = $pending;
        $this->view->emptyComposer();
        $this->showQueuedMessages();
    }

    private function prepareTurn(PendingMessage $pending): void
    {
        $this->preparingTurn = true;

        try {
            $this->view->acceptUserMessage($this->displayMessage($pending));
            $this->view->paintPendingChanges();
            $stream = $this->runtime->submitMessage($pending->message);
            $this->readyStream = $stream;
            $this->view->working($this->runtime->supportsResponseStop());
            $this->workingIndicator->start(microtime(true));
        } finally {
            $this->preparingTurn = false;
        }
    }

    private function displayMessage(PendingMessage $pending): UserMessage
    {
        return $pending->userInput
            ? $pending->message
            : $this->runtime->userMessageProcessors()->forDisplay($pending->message);
    }

    public function synchronizeHistory(): void
    {
        $history = $this->runtime->agent()->getChatHistory();

        if ($history->getThreadId() === $this->displayedHistory) {
            return;
        }

        $this->view->showHistory($history->getMessages());
        $this->displayedHistory = $history->getThreadId();
    }

    public function agent(): Agent
    {
        return $this->runtime->agent();
    }

    public function session(): Session
    {
        return $this->runtime->session();
    }

    public function isBusy(): bool
    {
        return $this->preparingTurn
            || $this->readyStream !== null
            || $this->runningTurn !== null
            || $this->pendingMessages !== [];
    }

    public function supportsResponseStop(): bool
    {
        return $this->runtime->supportsResponseStop();
    }

    public function isStopped(): bool
    {
        return $this->stopped;
    }

    public function useAgent(Agent $agent): void
    {
        $this->runtime->useAgent($agent);
    }

    public function useSession(Session $session): void
    {
        $this->runtime->useSession($session);
        $this->displayedHistory = null;
    }

    public function requestInterruption(): void
    {
        if (
            $this->runningTurn === null
            || $this->runningTurn->isComplete()
            || !$this->runtime->requestInterruption()
        ) {
            return;
        }

        $this->view->stopping();
        $this->view->paintPendingChanges();
    }

    public function tick(): bool
    {
        if ($this->stopped) {
            return false;
        }

        if ($this->runningTurn !== null) {
            if (!$this->runningTurn->isComplete()) {
                $this->workingIndicator->advance(microtime(true));

                return true;
            }

            try {
                $this->runningTurn->await();
            } catch (Throwable) {
                /* The renderer already presented the execution error. */
            }

            $this->runningTurn = null;
            $this->workingIndicator->stop();
            $this->view->ready();

            return $this->prepareNextTurn();
        }

        if ($this->readyStream === null) {
            return !$this->preparingTurn && $this->prepareNextTurn();
        }

        $stream = $this->readyStream;
        $this->readyStream = null;
        $this->runningTurn = async(function () use ($stream): void {
            $agent = $this->runtime->agent();
            $session = $this->runtime->session();
            $completed = $this->renderer->run($stream, $this->runtime->responseWasStopped(...));
            if ($completed && !$this->stopped && !$this->runtime->responseStopRequested()) {
                $this->titleGeneration?->schedule($session, $agent);
            }
        });

        return true;
    }

    private function prepareNextTurn(): bool
    {
        while ($this->pendingMessages !== []) {
            $pending = array_shift($this->pendingMessages);
            $this->showQueuedMessages();

            try {
                $this->prepareTurn($pending);

                return true;
            } catch (Throwable $error) {
                $this->view->showError($error->getMessage());
                // Never replace a newer draft. Original input is also in recall history.
                if ($this->view->isComposerEmpty()) {
                    $this->view->recallInput($pending->message);
                }
            }
        }

        return false;
    }

    private function showQueuedMessages(): void
    {
        $this->view->showQueuedMessages(array_map($this->displayMessage(...), $this->pendingMessages), prepared: false);
    }

    public function stop(): void
    {
        $this->stopped = true;
        $this->view->stop();
    }
}
