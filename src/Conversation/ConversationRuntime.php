<?php

declare(strict_types=1);

namespace NeuronTui\Conversation;

use Amp\Future;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronTui\View\ConversationView;
use NeuronTui\View\WorkingIndicator;
use Throwable;

use function Amp\async;

/**
 * Coordinates the answering Agent, Turn preparation, execution and presentation.
 *
 * @internal
 */
final class ConversationRuntime
{
    private readonly WorkingIndicator $workingIndicator;

    private readonly TurnQueue $turnQueue;

    private readonly TurnRunner $turnRunner;

    /** @var Future<mixed>|null */
    private ?Future $runningTurn = null;

    private ?TurnInterruption $interruption = null;

    private bool $stopped = false;

    private ?ChatHistoryInterface $displayedHistory = null;

    public function __construct(
        private Agent $agent,
        private readonly ConversationView $view,
    ) {
        $this->workingIndicator = $this->view->workingIndicator();
        $this->turnQueue = new TurnQueue();
        $this->turnRunner = new TurnRunner($this->view);
    }

    public function submitMessage(MessageForAgent $message): void
    {
        $this->view->emptyComposer();
        $accepted = $this->turnQueue->accept($message->content);

        if ($accepted === null) {
            $this->showQueuedMessages();

            return;
        }

        $this->showTurnStarted($accepted);
    }

    /** Synchronize a replaced History without repainting an unchanged conversation. */
    public function synchronizeHistory(): void
    {
        $history = $this->agent->getChatHistory();

        if ($history === $this->displayedHistory) {
            return;
        }

        $this->view->showHistory($history->getMessages());
        $this->displayedHistory = $history;
    }

    public function agent(): Agent
    {
        return $this->agent;
    }

    public function isBusy(): bool
    {
        return $this->turnQueue->isBusy();
    }

    public function requestInterruption(): void
    {
        if (
            $this->interruption === null
            || ($this->runningTurn !== null && $this->runningTurn->isComplete())
            || !$this->interruption->request()
        ) {
            return;
        }

        $this->view->interrupting();
        $this->view->paintPendingChanges();
    }

    public function isStopped(): bool
    {
        return $this->stopped;
    }

    /**
     * Puts another Agent in charge of answering from here on.
     *
     * A conversation is nobody's property: the History the Agent leaving was
     * answering is handed to the one taking over, so what is on the screen is
     * still what the Agent holds and nothing is said about the change until
     * the next answer, which comes from elsewhere. A command that knows the
     * two Agents are not interchangeable installs another History itself.
     */
    public function useAgent(Agent $agent): void
    {
        $agent->setChatHistory($this->agent->getChatHistory());
        $this->agent = $agent;
    }

    public function tick(): bool
    {
        if ($this->stopped) {
            return false;
        }

        $message = $this->turnQueue->takeForExecution();

        if ($message !== null) {
            // Capture the Agent when execution is scheduled, so this Turn
            // finishes with that Agent even if another takes over.
            $agent = $this->agent;
            $interruption = $this->interruption;
            $this->runningTurn = async(function () use ($agent, $message, $interruption): void {
                $this->turnRunner->run($agent, $message, $interruption);
            });

            return true;
        }

        if (!$this->runningTurn instanceof Future) {
            return false;
        }

        if (!$this->runningTurn->isComplete()) {
            $this->workingIndicator->advance(microtime(true));

            return true;
        }

        try {
            $this->runningTurn->await();
        } catch (WorkflowInterrupt $exception) {
            $this->view->showError(
                'Human-in-the-loop interruptions are not supported. '
                    . $exception->getMessage(),
            );
        } catch (Throwable $exception) {
            $this->showFailure($exception);
        }

        $this->runningTurn = null;
        $this->interruption = null;
        $this->showTurnFinished();

        $next = $this->turnQueue->finishAndAdvance();

        if ($next === null) {
            return false;
        }

        $this->showQueuedMessages();
        $this->showTurnStarted($next);

        return true;
    }

    /**
     * Shows the current message and starts the visible Working indicator.
     *
     * The queue has already prepared the Turn. Execution is scheduled by
     * tick(), both for a fresh submission and for a previously queued message.
     */
    private function showTurnStarted(string $message): void
    {
        $this->interruption = new TurnInterruption();
        $this->view->acceptUserMessage($message);
        $this->view->working();
        $this->workingIndicator->start(microtime(true));
    }

    /**
     * Shows what went wrong without the stack that says where.
     */
    private function showFailure(Throwable $exception): void
    {
        $this->view->showError(
            $exception::class . ': ' . $exception->getMessage(),
        );
    }

    private function showTurnFinished(): void
    {
        $this->workingIndicator->stop();
        $this->view->ready();
    }

    private function showQueuedMessages(): void
    {
        $this->view->showQueuedMessages($this->turnQueue->queuedMessages());
    }

    public function stop(): void
    {
        $this->stopped = true;
        $this->view->stop();
    }
}
