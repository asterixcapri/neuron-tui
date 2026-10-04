<?php

declare(strict_types=1);

namespace NeuronTui\Turn;

use Closure;
use Generator;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Command\AgentChanged;
use NeuronInteraction\Command\CommandInput;
use NeuronInteraction\Command\ExitRequest;
use NeuronInteraction\Command\Notification;
use NeuronInteraction\Command\NotificationLevel;
use NeuronInteraction\Command\SelectionOption;
use NeuronInteraction\Command\SelectionRequest;
use NeuronInteraction\Command\SessionChanged;
use NeuronInteraction\Conversation;
use NeuronTui\View\ChoiceOption;
use NeuronTui\View\ConversationView;
use Revolt\EventLoop;
use Throwable;

use function array_map;

/** Executes terminal turns by submitting inputs and consuming every Conversation event. @internal */
final class TurnRunner
{
    private ?string $displayedHistory = null;

    private bool $stopped = false;

    /** @param Closure(CommandInput): void $submitCommand */
    public function __construct(
        private readonly Conversation $conversation,
        private readonly ConversationView $view,
        private readonly Closure $submitCommand,
    ) {}

    /** Prepare human input eagerly; consume the stream to execute it.
     * @return Generator<int, object, mixed, AgentState|null>
     */
    public function sendInput(UserMessage|CommandInput $input): Generator
    {
        return $this->conversation->sendInput($input);
    }

    /**
     * Errors are presented and rethrown; false means a stopped or interrupted response.
     * A renderer belongs to this invocation so concurrent Commands cannot reset an active response.
     *
     * @param Generator<int, object, mixed, AgentState|null> $stream
     * @param (Closure(): bool)|null $responseWasStopped
     */
    public function run(Generator $stream, ?Closure $responseWasStopped = null): bool
    {
        $responseWasStopped ??= $this->conversation->responseWasStopped(...);
        $renderer = new TurnRenderer($this->view);

        try {
            foreach ($stream as $event) {
                if ($event instanceof Notification) {
                    $this->notify($event);
                } elseif ($event instanceof SessionChanged) {
                    $this->invalidateHistory();
                    $this->synchronizeHistory();
                    $this->view->paintPendingChanges();
                } elseif ($event instanceof AgentChanged) {
                    $this->synchronizeHistory();
                    $this->view->paintPendingChanges();
                } elseif ($event instanceof ExitRequest) {
                    $this->stop();
                    $this->view->paintPendingChanges();
                } elseif ($event instanceof SelectionRequest) {
                    $this->select($event);
                } elseif ($event instanceof ToolCallChunk) {
                    $renderer->toolCall($event);
                } elseif ($event instanceof ToolResultChunk) {
                    $renderer->toolResult($event);
                } elseif ($event instanceof TextChunk) {
                    $renderer->text($event);
                } else {
                    $renderer->beginResponse();
                }
            }

            $state = $stream->getReturn();
            if ($state === null) {
                return true;
            }
            if ($responseWasStopped()) {
                $this->showStoppedResponse();
                return false;
            }
            if ($state->isInterrupted()) {
                $this->view->showError('Human-in-the-loop interruptions are not supported.');
                return false;
            }
            $renderer->finish();
            return true;
        } catch (Throwable $error) {
            $this->view->showError($error::class . ': ' . $error->getMessage());
            if ($responseWasStopped()) {
                $this->showStoppedResponse();
            }
            throw $error;
        }
    }

    private function notify(Notification $event): void
    {
        $this->synchronizeHistory();
        $this->view->endAgentMessage();
        match ($event->level) {
            NotificationLevel::Info => $this->view->showNotice($event->text),
            NotificationLevel::Warning => $this->view->showWarning($event->text),
            NotificationLevel::Error => $this->view->showError($event->text),
        };
        $this->view->paintPendingChanges();
    }

    private function select(SelectionRequest $event): void
    {
        EventLoop::queue(function () use ($event): void {
            if ($this->isStopped()) {
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
                // Scheduling rechecks exit and admission after the picker suspends.
                if ($value !== null) {
                    ($this->submitCommand)(new CommandInput($event->command, $value));
                }
            } catch (Throwable $error) {
                $this->view->showError($error::class . ': ' . $error->getMessage());
            }
        });
        $this->view->paintPendingChanges();
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

    public function invalidateHistory(): void
    {
        $this->displayedHistory = null;
    }

    private function showStoppedResponse(): void
    {
        $this->view->workingIndicator()->stop();
        $this->view->showResponseStopped();
    }

    public function stop(): void
    {
        $this->stopped = true;
        $this->view->stop();
    }

    public function isStopped(): bool
    {
        return $this->stopped;
    }
}
