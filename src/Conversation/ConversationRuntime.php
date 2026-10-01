<?php

declare(strict_types=1);

namespace NeuronTui\Conversation;

use Amp\Future;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronChatCore\Command\CommandAdapterInterface;
use NeuronChatCore\Command\CommandInterface;
use NeuronChatCore\Command\Commands;
use NeuronChatCore\Conversation\ConversationRuntime as CoreRuntime;
use NeuronChatCore\Session\Session;
use NeuronTui\Session\SessionTitleGeneration;
use NeuronTui\View\ConversationView;
use NeuronTui\View\WorkingIndicator;
use Throwable;

use function Amp\async;
use function microtime;

/** Terminal scheduling and presentation for the host's core runtime. @internal */
final class ConversationRuntime
{
    private readonly WorkingIndicator $workingIndicator;
    /** @var Future<mixed>|null */
    private ?Future $runningTurn = null;
    private bool $stopped = false;
    private bool $turnPresented = false;
    private ?string $displayedHistory = null;

    public function __construct(
        private readonly CoreRuntime $core,
        private readonly ConversationView $view,
        private readonly ?SessionTitleGeneration $titleGeneration = null,
    ) {
        $this->workingIndicator = $view->workingIndicator();
        $renderer = new TurnEventRenderer($view);
        $core->subscribe($renderer->consume(...));
    }

    public function submitMessage(UserMessage $message): void
    {
        $this->view->emptyComposer();
        $idle = !$this->core->isBusy() && $this->runningTurn === null;
        $this->core->submitMessage($message);
        if ($idle) {
            $this->showTurnStarted($message);
        } else {
            $this->showQueuedMessages();
        }
    }
    public function synchronizeHistory(): void
    {
        $history = $this->core->agent()->getChatHistory();
        if ($history->getThreadId() === $this->displayedHistory) {
            return;
        }
        $this->view->showHistory($history->getMessages());
        $this->displayedHistory = $history->getThreadId();
    }
    public function agent(): Agent
    {
        return $this->core->agent();
    }
    public function session(): Session
    {
        return $this->core->session();
    }
    public function isBusy(): bool
    {
        return $this->core->isBusy();
    }
    public function isCommandAvailable(CommandInterface $command): bool
    {
        return $this->core->isCommandAvailable($command);
    }
    /**
     * @template TOutput
     * @param CommandAdapterInterface<TOutput> $adapter
     * @return TOutput|null
     */
    public function runCommand(Commands $commands, string $identifier, string $value, CommandAdapterInterface $adapter): mixed
    {
        return $this->core->runCommand($commands, $identifier, $value, $adapter);
    }
    public function supportsResponseStop(): bool
    {
        return $this->core->supportsResponseStop();
    }
    public function isStopped(): bool
    {
        return $this->stopped;
    }
    public function useAgent(Agent $agent): void
    {
        $this->core->useAgent($agent);
    }
    public function useSession(Session $session): void
    {
        $this->core->useSession($session);
        $this->displayedHistory = null;
    }
    public function requestInterruption(): void
    {
        if ($this->runningTurn?->isComplete() === true || !$this->core->requestInterruption()) {
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
            } catch (Throwable) { /* Errors have already been rendered through events. */
            }
            $this->runningTurn = null;
            $this->turnPresented = false;
            $this->workingIndicator->stop();
            $this->view->ready();
            $next = $this->core->readyMessage();
            $this->showQueuedMessages();
            if ($next === null) {
                return false;
            }
            $this->showTurnStarted($next);
            return true;
        }
        $ready = $this->core->readyMessage();
        if ($ready === null) {
            return false;
        }
        if (!$this->turnPresented) {
            $this->showQueuedMessages();
            $this->showTurnStarted($ready);
        }
        $this->runningTurn = async(function (): void {
            $result = $this->core->executeNext();
            if ($result?->completed && !$this->stopped && !$result->responseStopRequested) {
                $this->titleGeneration?->schedule($result->session, $result->agent);
            }
        });
        return true;
    }
    private function showTurnStarted(UserMessage $message): void
    {
        $this->turnPresented = true;
        $this->view->acceptUserMessage($message);
        $this->view->working($this->core->supportsResponseStop());
        $this->workingIndicator->start(microtime(true));
    }
    private function showQueuedMessages(): void
    {
        $this->view->showQueuedMessages($this->core->queuedMessages());
    }
    public function stop(): void
    {
        $this->stopped = true;
        $this->view->stop();
    }
}
