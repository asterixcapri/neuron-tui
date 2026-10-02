<?php

declare(strict_types=1);

namespace NeuronTui\Turn;

use Amp\Future;
use Generator;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Conversation;
use NeuronInteraction\Session\Session;
use NeuronTui\Session\SessionTitleGeneration;
use NeuronTui\View\ConversationView;
use NeuronTui\View\WorkingIndicator;
use Throwable;

use function Amp\async;
use function array_map;
use function array_shift;
use function microtime;

/** Queues pending messages and starts terminal turns on a Conversation. @internal */
final class TurnScheduler
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
        private readonly Conversation $conversation,
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
            $stream = $this->conversation->submitMessage($pending->message);
            $this->readyStream = $stream;
            $this->view->working($this->conversation->supportsResponseStop());
            $this->workingIndicator->start(microtime(true));
        } finally {
            $this->preparingTurn = false;
        }
    }

    private function displayMessage(PendingMessage $pending): UserMessage
    {
        return $pending->userInput
            ? $pending->message
            : $this->conversation->userMessageProcessors()->forDisplay($pending->message);
    }

    public function synchronizeHistory(): void
    {
        $history = $this->conversation->agent()->getChatHistory();

        if ($history->getThreadId() === $this->displayedHistory) {
            return;
        }

        $this->view->showHistory($history->getMessages());
        $this->displayedHistory = $history->getThreadId();
    }

    public function agent(): Agent
    {
        return $this->conversation->agent();
    }

    public function session(): Session
    {
        return $this->conversation->session();
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
        return $this->conversation->supportsResponseStop();
    }

    public function isStopped(): bool
    {
        return $this->stopped;
    }

    public function useAgent(Agent $agent): void
    {
        $this->conversation->useAgent($agent);
    }

    public function useSession(Session $session): void
    {
        $this->conversation->useSession($session);
        $this->displayedHistory = null;
    }

    public function requestInterruption(): void
    {
        if (
            $this->runningTurn === null
            || $this->runningTurn->isComplete()
            || !$this->conversation->requestInterruption()
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
            $agent = $this->conversation->agent();
            $session = $this->conversation->session();
            $completed = $this->renderer->run($stream, $this->conversation->responseWasStopped(...));
            if ($completed && !$this->stopped && !$this->conversation->responseStopRequested()) {
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
