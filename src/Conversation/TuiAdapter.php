<?php

declare(strict_types=1);

namespace NeuronTui\Conversation;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronInteraction\Command\CommandAdapterInterface;
use NeuronInteraction\Command\CommandArguments;
use NeuronInteraction\Command\CommandExecution;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\SelectionOption;
use NeuronInteraction\Command\SelectionRequest;
use NeuronInteraction\Session\Sessions;
use NeuronTui\Tui\ConversationView;
use Revolt\EventLoop;
use Throwable;

/**
 * Terminal behavior before, during, and after one Command invocation.
 *
 * @implements CommandAdapterInterface<null>
 * @internal Commands depend on the shared interface, not this Adapter.
 */
final class TuiAdapter implements CommandAdapterInterface
{
    private ?ChatHistoryInterface $previousHistory = null;

    public function __construct(
        private readonly ConversationRuntime $runtime,
        private readonly ConversationView $view,
        private readonly Commands $commands,
        private readonly Sessions $sessions,
    ) {
    }

    public function admit(CommandInterface $command): bool
    {
        if ($this->runtime->isBusy() && !ConcurrentCommands::allows($command)) {
            $this->view->showError(
                $command->name()
                    . ' is refused while the Agent is working. '
                    . 'Try it again once the turn has finished.',
            );

            return false;
        }

        $this->view->emptyComposer();
        $this->previousHistory = $this->agent()->getChatHistory();

        return true;
    }

    public function afterExecution(CommandExecution $execution): null
    {
        if ($execution->status === 'unknown') {
            $this->view->showUnknownCommand($execution->identifier);

            return null;
        }

        // Reconcile first, so a failure remains visible on the resulting
        // conversation. The same History preserves notices and warnings.
        $current = $this->agent()->getChatHistory();

        if ($current !== $this->previousHistory) {
            $this->view->showHistory($current->getMessages());
        }

        if ($execution->exception instanceof Throwable) {
            $this->showFailure($execution->exception);
        }

        return null;
    }

    public function say(string $text): void
    {
        $this->view->showNotice($text);
    }

    public function warn(string $text): void
    {
        $this->view->showError($text);
    }

    public function promptAgent(string $prompt): void
    {
        $this->runtime->send(new MessageForAgent($prompt));
    }

    public function requestSelection(SelectionRequest $request): void
    {
        // Presentation happens after this invocation has returned. Its
        // continuation reads the live runtime through a fresh Adapter.
        EventLoop::queue(function () use ($request): void {
            if ($this->runtime->isStopped()) {
                return;
            }

            try {
                $chosen = $this->view->choose(
                    $request->prompt,
                    array_map(
                        static fn (SelectionOption $option): ChoiceOption => new ChoiceOption(
                            $option->value,
                            $option->label,
                            $option->description,
                        ),
                        $request->options,
                    ),
                    $request->description,
                );

                if ($chosen !== null) {
                    $this->commands->run(
                        $request->command,
                        new CommandArguments($chosen),
                        new self($this->runtime, $this->view, $this->commands, $this->sessions),
                    );
                }
            } catch (Throwable $exception) {
                $this->showFailure($exception);
            }
        });
    }

    public function agent(): Agent
    {
        return $this->runtime->agent();
    }

    public function useAgent(Agent $agent): void
    {
        $this->runtime->useAgent($agent);
    }

    public function commands(): Commands
    {
        return $this->commands;
    }

    public function sessions(): Sessions
    {
        return $this->sessions;
    }

    public function stop(): void
    {
        $this->runtime->stop();
    }

    private function showFailure(Throwable $exception): void
    {
        $this->view->showError($exception::class . ': ' . $exception->getMessage());
    }
}
