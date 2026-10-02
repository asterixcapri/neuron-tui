<?php

declare(strict_types=1);

namespace NeuronTui\Command;

use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Command\AbstractCommandAdapter;
use NeuronInteraction\Command\CommandExecution;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\ConcurrentCommandInterface;
use NeuronInteraction\Command\Selection;
use NeuronInteraction\Command\SelectionOption;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Conversation;
use NeuronInteraction\Session\Session;
use NeuronTui\Turn\TurnScheduler;
use NeuronTui\View\ChoiceOption;
use NeuronTui\View\ConversationView;
use Revolt\EventLoop;
use Throwable;

use function array_map;

/**
 * Terminal behavior before, during, and after one Command invocation.
 *
 * @extends AbstractCommandAdapter<null>
 * @internal Commands depend on the shared interface, not this Adapter.
 */
final class TuiCommandAdapter extends AbstractCommandAdapter
{
    public function __construct(
        private readonly TurnScheduler $scheduler,
        private readonly ConversationView $view,
        private readonly Commands $commands,
        Conversation $conversation,
        private readonly ConfigurationStore $configurationStore,
    ) {
        parent::__construct($conversation);
    }

    public function admit(CommandInterface $command): bool
    {
        if ($this->scheduler->isBusy() && !$command instanceof ConcurrentCommandInterface) {
            $this->view->showError(
                $command->name()
                    . ' is refused while the Agent is working. '
                    . 'Try it again once the turn has finished.',
            );

            return false;
        }

        $this->view->emptyComposer();

        return true;
    }

    public function afterExecution(CommandExecution $execution): null
    {
        $this->scheduler->synchronizeHistory();

        if ($execution->status === 'unknown') {
            $this->view->showUnknownCommand($execution->identifier);

            return null;
        }

        if ($execution->exception instanceof Throwable) {
            $this->showFailure($execution->exception);
        }

        return null;
    }

    public function notify(string $text): void
    {
        $this->scheduler->synchronizeHistory();

        $this->view->showNotice($text);
    }

    public function warn(string $text): void
    {
        $this->scheduler->synchronizeHistory();

        $this->view->showWarning($text);
    }

    public function error(string $text): void
    {
        $this->scheduler->synchronizeHistory();

        $this->view->showError($text);
    }

    public function promptAgent(UserMessage $prompt): void
    {
        $this->scheduler->synchronizeHistory();

        $this->scheduler->enqueueMessage($prompt);
    }

    public function requestSelection(Selection $request): void
    {
        // Presentation happens after this invocation has returned. Its
        // continuation reads the live scheduler through a fresh Adapter.
        EventLoop::queue(function () use ($request): void {
            if ($this->scheduler->isStopped()) {
                return;
            }

            try {
                $chosen = $this->view->choose(
                    $request->prompt,
                    array_map(
                        fn(SelectionOption $option): ChoiceOption => new ChoiceOption(
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
                        $chosen,
                        new self($this->scheduler, $this->view, $this->commands, $this->conversation, $this->configurationStore),
                    );
                }
            } catch (Throwable $exception) {
                $this->showFailure($exception);
            }
        });
    }

    public function useSession(Session $session): void
    {
        $this->scheduler->useSession($session);
    }

    public function commands(): Commands
    {
        return $this->commands;
    }

    public function configurationStore(): ConfigurationStore
    {
        return $this->configurationStore;
    }

    public function stop(): void
    {
        $this->scheduler->stop();
    }

    private function showFailure(Throwable $exception): void
    {
        $this->view->showError($exception::class . ': ' . $exception->getMessage());
    }
}
