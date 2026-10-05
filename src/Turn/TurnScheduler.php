<?php

declare(strict_types=1);

namespace NeuronTui\Turn;

use Amp\Future;
use Closure;
use Generator;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Command\CommandInput;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Conversation;
use NeuronInteraction\Session\Session;
use NeuronInteraction\Session\SessionTitleGenerator;
use NeuronTui\Command\CommandAvailability;
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

    /** The reservation for this Command's own task must not refuse its admission. */
    private ?bool $commandAdmissionBusy = null;

    private bool $preparingTurn = false;

    private readonly TurnRunner $runner;

    /** @var Generator<int, object, mixed, AgentState|null>|null */
    private ?Generator $readyStream = null;

    /** @var list<UserMessage> */
    private array $pendingMessages = [];

    /** @var array<string, true> */
    private array $runningTitles = [];

    public function __construct(
        private readonly Conversation $conversation,
        private readonly ConversationView $view,
    ) {
        $this->workingIndicator = $view->workingIndicator();
        $this->runner = new TurnRunner($conversation, $view, $this->submitCommand(...));
    }

    public function admitCommand(CommandInterface $command): bool
    {
        $busy = $this->commandAdmissionBusy ?? $this->isBusy();
        $this->commandAdmissionBusy = null;
        if ($busy && !CommandAvailability::whileWorking($command)) {
            return false;
        }
        $this->view->emptyComposer();
        return true;
    }

    /** Commands execute immediately; only human messages enter the FIFO. */
    public function submitCommand(CommandInput $input): void
    {
        if ($this->isStopped()) {
            return;
        }
        $busy = $this->isBusy();
        $stream = $this->runner->sendInput($input);
        $turn = async(function () use ($stream, $busy): void {
            $this->consumeStream(
                $this->commandStream($stream, $busy),
                fn() => $this->scheduleSessionTitle($this->conversation->session(), $this->conversation->agent()),
            );
        });
        if (!$busy) {
            $this->runningTurn = $turn;
            $this->view->working($this->conversation->supportsResponseStop());
            $this->workingIndicator->start(microtime(true));
        }
    }

    /** @param Generator<int, object, mixed, AgentState|null> $stream
     * @return Generator<int, object, mixed, AgentState|null>
     */
    private function commandStream(Generator $stream, bool $busy): Generator
    {
        $this->commandAdmissionBusy = $busy;
        try {
            return yield from $stream;
        } finally {
            $this->commandAdmissionBusy = null;
        }
    }

    /** Queue a message for preparation when its turn starts. */
    public function enqueueMessage(UserMessage $message): void
    {
        $this->pendingMessages[] = clone $message;
        $this->view->emptyComposer();
        $this->showQueuedMessages();
    }

    private function prepareTurn(UserMessage $message): void
    {
        $this->preparingTurn = true;

        try {
            $this->view->acceptUserMessage($this->displayMessage($message));
            $this->view->paintPendingChanges();
            $stream = $this->runner->sendInput($message);
            $this->readyStream = $stream;
            $this->view->working($this->conversation->supportsResponseStop());
            $this->workingIndicator->start(microtime(true));
        } finally {
            $this->preparingTurn = false;
        }
    }

    private function displayMessage(UserMessage $message): UserMessage
    {
        return $this->conversation->userMessageProcessors()->forDisplay($message);
    }

    public function synchronizeHistory(): void
    {
        $this->runner->synchronizeHistory();
    }

    public function agent(): Agent
    {
        return $this->conversation->agent();
    }

    public function session(): Session
    {
        return $this->conversation->session();
    }

    /** Includes queued messages, preparation, a ready stream, and response execution. */
    public function isBusy(): bool
    {
        return $this->preparingTurn
            || $this->readyStream !== null
            || ($this->runningTurn !== null && !$this->runningTurn->isComplete())
            || $this->pendingMessages !== [];
    }

    public function supportsResponseStop(): bool
    {
        return $this->conversation->supportsResponseStop();
    }

    public function isStopped(): bool
    {
        return $this->runner->isStopped();
    }

    public function useAgent(Agent $agent): void
    {
        $this->conversation->useAgent($agent);
    }

    public function useSession(Session $session): void
    {
        $this->conversation->useSession($session);
        $this->runner->invalidateHistory();
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
        if ($this->isStopped()) {
            return false;
        }

        if ($this->runningTurn !== null) {
            if (!$this->runningTurn->isComplete()) {
                $this->workingIndicator->advance(microtime(true));

                return true;
            }

            $this->finishRunningTurn($this->runningTurn);

            return $this->prepareNextTurn();
        }

        if ($this->readyStream === null) {
            return !$this->preparingTurn && $this->prepareNextTurn();
        }

        $this->startReadyStream($this->readyStream);

        return true;
    }

    /** @param Future<mixed> $turn */
    private function finishRunningTurn(Future $turn): void
    {
        try {
            $turn->await();
        } catch (Throwable $error) {
            $this->view->showError($error::class . ': ' . $error->getMessage());
        } finally {
            $this->runningTurn = null;
            $this->workingIndicator->stop();
            $this->view->ready();
        }
    }

    /** @param Generator<int, object, mixed, AgentState|null> $stream */
    private function startReadyStream(Generator $stream): void
    {
        $this->readyStream = null;
        $this->runningTurn = async(function () use ($stream): void {
            $agent = $this->conversation->agent();
            $session = $this->conversation->session();

            $this->consumeStream($stream, fn() => $this->scheduleSessionTitle($session, $agent));
        });
    }

    /**
     * @param Generator<int, object, mixed, AgentState|null> $stream
     * @param Closure(): void $onCompleted
     */
    private function consumeStream(Generator $stream, Closure $onCompleted): void
    {
        try {
            $completed = $this->runner->run($stream);
        } catch (Throwable) {
            // The runner already presented the streaming error.
            return;
        }

        if ($stream->getReturn() !== null && $completed && !$this->isStopped() && !$this->conversation->responseStopRequested()) {
            $onCompleted();
        }
    }

    private function scheduleSessionTitle(Session $session, Agent $agent): void
    {
        $key = $session->getKey();
        if (isset($this->runningTitles[$key])) {
            return;
        }

        $this->runningTitles[$key] = true;
        async(function () use ($session, $key, $agent): void {
            try {
                $provider = clone $agent->getProvider();
                (new SessionTitleGenerator($provider, $session))->generate();
            } catch (Throwable) {
                // Optional title generation must not interrupt the conversation.
            } finally {
                unset($this->runningTitles[$key]);
            }
        });
    }

    private function prepareNextTurn(): bool
    {
        while ($this->pendingMessages !== []) {
            $message = array_shift($this->pendingMessages);
            $this->showQueuedMessages();

            try {
                $this->prepareTurn($message);

                return true;
            } catch (Throwable $error) {
                $this->view->showError($error->getMessage());
                // Never replace a newer draft. Original input is also in recall history.
                if ($this->view->isComposerEmpty()) {
                    $this->view->recallInput($message);
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
        $this->runner->stop();
    }
}
