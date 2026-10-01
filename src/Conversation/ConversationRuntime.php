<?php

declare(strict_types=1);

namespace NeuronTui\Conversation;

use Amp\Future;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Http\StopSignal;
use NeuronInteraction\Session\Session;
use NeuronTui\Session\SessionTitleGeneration;
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

    private bool $stopped = false;

    private bool $responseStopRequested = false;

    private ?string $displayedHistory = null;

    public function __construct(
        private Agent $agent,
        private readonly ConversationView $view,
        private Session $session,
        private readonly ?StopSignal $stopSignal = null,
        private readonly ?SessionTitleGeneration $titleGeneration = null,
    ) {
        $this->agent = $this->session->bindTo($agent);
        $this->workingIndicator = $this->view->workingIndicator();
        $this->turnQueue = new TurnQueue();
        $this->turnRunner = new TurnRunner($this->view);
    }

    public function submitMessage(UserMessage $message): void
    {
        $this->view->emptyComposer();
        $accepted = $this->turnQueue->accept($message);

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

        if ($history->getThreadId() === $this->displayedHistory) {
            return;
        }

        $this->view->showHistory($history->getMessages());
        $this->displayedHistory = $history->getThreadId();
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
            $this->stopSignal === null
            || !$this->isBusy()
            || ($this->runningTurn !== null && $this->runningTurn->isComplete())
            || $this->responseStopRequested
        ) {
            return;
        }

        $this->stopSignal->request();
        $this->responseStopRequested = true;
        $this->view->stopping();
        $this->view->paintPendingChanges();
    }

    public function supportsResponseStop(): bool
    {
        return $this->stopSignal !== null;
    }

    /** A requested flag disappears when the StopSignal callback consumes it. */
    private function responseWasStopped(): bool
    {
        return $this->responseStopRequested
            && $this->stopSignal?->isRequested() === false;
    }

    public function isStopped(): bool
    {
        return $this->stopped;
    }

    /** Replace the answering Agent while retaining the selected conversation. */
    public function useAgent(Agent $agent): void
    {
        $this->agent = $this->session->bindTo($agent);
    }

    public function session(): Session
    {
        return $this->session;
    }

    /** Select another conversation without changing the Agent's configuration. */
    public function useSession(Session $session): void
    {
        $agent = $session->bindTo($this->agent);
        $this->session = $session;
        $this->agent = $agent;
        $this->displayedHistory = null;
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
            $session = $this->session;
            $this->runningTurn = async(function () use ($agent, $message, $session): void {
                $completed = $this->turnRunner->run($agent, $message, $this->responseWasStopped(...));
                if ($completed && !$this->stopped && !$this->responseStopRequested) {
                    $this->titleGeneration?->schedule($session, $agent);
                }
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
        } catch (Throwable $exception) {
            $this->showFailure($exception);
        }

        $this->runningTurn = null;
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
    private function showTurnStarted(UserMessage $message): void
    {
        $this->responseStopRequested = false;
        $this->stopSignal?->clear();
        $this->view->acceptUserMessage($message);
        $this->view->working($this->stopSignal !== null);
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
