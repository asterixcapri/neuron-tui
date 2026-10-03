<?php

declare(strict_types=1);

namespace NeuronTui\Turn;

use Amp\Future;
use Generator;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Command\AgentChanged;
use NeuronInteraction\Command\CommandInput;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\ExitRequest;
use NeuronInteraction\Command\Notification;
use NeuronInteraction\Command\NotificationLevel;
use NeuronInteraction\Command\SelectionOption;
use NeuronInteraction\Command\SelectionRequest;
use NeuronInteraction\Command\SessionChanged;
use NeuronInteraction\Conversation;
use NeuronInteraction\Session\Session;
use NeuronInteraction\Session\SessionTitleGenerator;
use NeuronTui\Command\CommandAvailability;
use NeuronTui\View\ChoiceOption;
use NeuronTui\View\ConversationView;
use NeuronTui\View\WorkingIndicator;
use Revolt\EventLoop;
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

    /** The reservation for this Command's own task must not refuse its admission. */
    private ?bool $commandAdmissionBusy = null;

    private bool $preparingTurn = false;

    private readonly TurnRenderer $renderer;

    /** @var Generator<int, object, mixed, AgentState|null>|null */
    private ?Generator $readyStream = null;

    /** @var list<UserMessage> */
    private array $pendingMessages = [];

    private ?string $displayedHistory = null;

    /** @var array<string, true> */
    private array $runningTitles = [];

    public function __construct(
        private readonly Conversation $conversation,
        private readonly ConversationView $view,
    ) {
        $this->workingIndicator = $view->workingIndicator();
        $this->renderer = new TurnRenderer($view);
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
        if ($this->stopped) {
            return;
        }
        $busy = $this->isBusy();
        $stream = $this->conversation->submitInput($input);
        $turn = async(function () use ($stream, $busy): void {
            try {
                $completed = (new TurnRenderer($this->view))->run(
                    $this->commandStream($stream, $busy),
                    $this->conversation->responseWasStopped(...),
                    $this->consumeInteractionEvent(...),
                );
            } catch (Throwable) {
                return;
            }
            if ($stream->getReturn() !== null && $completed && !$this->stopped && !$this->conversation->responseStopRequested()) {
                $this->scheduleSessionTitle($this->conversation->session(), $this->conversation->agent());
            }
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

    private function consumeInteractionEvent(object $event): bool
    {
        if ($event instanceof Notification) {
            $this->synchronizeHistory();
            $this->view->endAgentMessage();
            match ($event->level) {
                NotificationLevel::Info => $this->view->showNotice($event->text),
                NotificationLevel::Warning => $this->view->showWarning($event->text),
                NotificationLevel::Error => $this->view->showError($event->text),
            };
        } elseif ($event instanceof SessionChanged || $event instanceof AgentChanged) {
            if ($event instanceof SessionChanged) {
                $this->displayedHistory = null;
            }
            $this->synchronizeHistory();
        } elseif ($event instanceof ExitRequest) {
            $this->stop();
        } elseif ($event instanceof SelectionRequest) {
            EventLoop::queue(function () use ($event): void {
                if ($this->stopped) {
                    return;
                }
                try {
                    $value = $this->view->choose(
                        $event->prompt,
                        array_map(static fn(SelectionOption $option): ChoiceOption => new ChoiceOption(
                            $option->value,
                            $option->label,
                            $option->description,
                        ), $event->options),
                        $event->description,
                    );
                    if ($value !== null && !$this->isStopped()) {
                        $this->submitCommand(new CommandInput($event->command, $value));
                    }
                } catch (Throwable $error) {
                    $this->view->showError($error::class . ': ' . $error->getMessage());
                }
            });
        } else {
            return false;
        }
        $this->view->paintPendingChanges();
        return true;
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
            $stream = $this->conversation->submitInput($message);
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

            try {
                $completed = $this->renderer->run($stream, $this->conversation->responseWasStopped(...), $this->consumeInteractionEvent(...));
            } catch (Throwable) {
                // The renderer already presented the streaming error.
                return;
            }

            if ($completed && !$this->stopped && !$this->conversation->responseStopRequested()) {
                $this->scheduleSessionTitle($session, $agent);
            }
        });
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
        $this->stopped = true;
        $this->view->stop();
    }
}
