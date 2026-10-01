<?php

declare(strict_types=1);

namespace NeuronTui\Command;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronChatCore\Command\CommandAdapterInterface;
use NeuronChatCore\Command\CommandExecution;
use NeuronChatCore\Command\CommandInterface;
use NeuronChatCore\Command\Commands;
use NeuronChatCore\Command\ConcurrentCommandInterface;
use NeuronChatCore\Command\Selection;
use NeuronChatCore\Command\SelectionOption;
use NeuronChatCore\Configuration\ConfigurationStore;
use NeuronChatCore\Session\Session;
use NeuronChatCore\Session\SessionStore;
use NeuronTui\Conversation\ConversationController;
use NeuronTui\View\ChoiceOption;
use NeuronTui\View\ConversationView;
use Revolt\EventLoop;
use Throwable;

use function array_map;

/**
 * Terminal behavior before, during, and after one Command invocation.
 *
 * @implements CommandAdapterInterface<null>
 * @internal Commands depend on the shared interface, not this Adapter.
 */
final class TuiCommandAdapter implements CommandAdapterInterface
{
    public function __construct(
        private readonly ConversationController $controller,
        private readonly ConversationView $view,
        private readonly Commands $commands,
        private readonly SessionStore $sessionStore,
        private readonly ConfigurationStore $configurationStore,
    ) {}

    public function admit(CommandInterface $command): bool
    {
        if ($this->controller->isBusy() && !$command instanceof ConcurrentCommandInterface) {
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
        $this->controller->synchronizeHistory();

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
        $this->controller->synchronizeHistory();

        $this->view->showNotice($text);
    }

    public function warn(string $text): void
    {
        $this->controller->synchronizeHistory();

        $this->view->showWarning($text);
    }

    public function error(string $text): void
    {
        $this->controller->synchronizeHistory();

        $this->view->showError($text);
    }

    public function promptAgent(UserMessage $prompt): void
    {
        $this->controller->synchronizeHistory();

        $this->controller->submitMessage($prompt);
    }

    public function requestSelection(Selection $request): void
    {
        // Presentation happens after this invocation has returned. Its
        // continuation reads the live controller through a fresh Adapter.
        EventLoop::queue(function () use ($request): void {
            if ($this->controller->isStopped()) {
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
                        new self($this->controller, $this->view, $this->commands, $this->sessionStore, $this->configurationStore),
                    );
                }
            } catch (Throwable $exception) {
                $this->showFailure($exception);
            }
        });
    }

    public function agent(): Agent
    {
        return $this->controller->agent();
    }

    public function useAgent(Agent $agent): void
    {
        $this->controller->useAgent($agent);
    }

    public function session(): Session
    {
        return $this->controller->session();
    }

    public function useSession(Session $session): void
    {
        $this->controller->useSession($session);
    }

    public function commands(): Commands
    {
        return $this->commands;
    }

    public function sessionStore(): SessionStore
    {
        return $this->sessionStore;
    }

    public function configurationStore(): ConfigurationStore
    {
        return $this->configurationStore;
    }

    public function stop(): void
    {
        $this->controller->stop();
    }

    private function showFailure(Throwable $exception): void
    {
        $this->view->showError($exception::class . ': ' . $exception->getMessage());
    }
}
