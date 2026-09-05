<?php

declare(strict_types=1);

namespace NeuronTui\Conversation;

use Amp\Future;
use NeuronAI\Agent\Agent;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronTui\Tui\ConversationView;
use NeuronTui\Tui\WorkingIndicator;
use Throwable;

use function Amp\async;

/**
 * Owns the current answering Agent and the accepted and responding Turns.
 *
 * @internal
 */
final class ConversationRuntime
{
    private readonly WorkingIndicator $workingIndicator;

    private readonly TurnQueue $turns;

    private readonly AgentTurn $agentTurn;

    /** @var Future<mixed>|null */
    private ?Future $response = null;

    private bool $stopped = false;

    public function __construct(
        private Agent $agent,
        private readonly ConversationView $view,
    ) {
        $this->workingIndicator = $this->view->workingIndicator();
        $this->turns = new TurnQueue();
        $this->agentTurn = new AgentTurn($this->view);
    }

    public function send(MessageForAgent $message): void
    {
        $accepted = $this->turns->accept($message->contents);

        if ($accepted === null) {
            $this->showQueue();

            return;
        }

        $this->beginTurn($accepted);
    }

    public function agent(): Agent
    {
        return $this->agent;
    }

    public function isBusy(): bool
    {
        return $this->turns->isBusy();
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

        $message = $this->turns->beginWorking();

        if ($message !== null) {
            // The Agent is read the moment the turn starts, so a turn under
            // way ends with the one that took it.
            $agent = $this->agent;
            $this->response = async(function () use ($agent, $message): void {
                $this->agentTurn->respond($agent, $message);
            });

            return true;
        }

        if (!$this->response instanceof Future) {
            return false;
        }

        if (!$this->response->isComplete()) {
            $this->workingIndicator->advance(microtime(true));

            return true;
        }

        try {
            $this->response->await();
        } catch (WorkflowInterrupt $exception) {
            $this->view->showError(
                'Human-in-the-loop interruptions are not supported. '
                    . $exception->getMessage(),
            );
        } catch (Throwable $exception) {
            $this->showFailure($exception);
        }

        $this->response = null;
        $this->finishTurn();

        $next = $this->turns->finishWorking();

        if ($next === null) {
            return false;
        }

        $this->showQueue();
        $this->beginTurn($next);

        return true;
    }

    /**
     * Shows the message as the person's own and puts the TUI to work.
     *
     * The one transition from an accepted message to a turn in flight, taken
     * both by a message straight from the composer and by one that waited.
     */
    private function beginTurn(string $message): void
    {
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

    private function finishTurn(): void
    {
        $this->workingIndicator->stop();
        $this->view->ready();
    }

    private function showQueue(): void
    {
        $this->view->showQueuedMessages($this->turns->queued());
    }

    public function stop(): void
    {
        $this->stopped = true;
        $this->view->stop();
    }
}
