<?php

declare(strict_types=1);

namespace NeuronTui\Conversation;

use Amp\Future;
use NeuronAI\Agent\Agent;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\InputHistory\InputHistory;
use NeuronInteraction\Session\Sessions;
use NeuronTui\Tui\ConversationView;
use NeuronTui\Tui\WorkingIndicator;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Terminal\Terminal;
use Symfony\Component\Tui\Terminal\TerminalInterface;
use Throwable;

use function Amp\async;

/**
 * The assembled Conversation TUI and its live Session state.
 *
 * @internal The public entry point is responsible for configuration and uses
 *     this single boundary to assemble and run the terminal conversation.
 */
final class ConversationRuntime
{
    private readonly TerminalInterface $terminal;

    private readonly ConversationView $view;

    private readonly WorkingIndicator $workingIndicator;

    private readonly TurnQueue $turns;

    private readonly AgentTurn $agentTurn;

    /** @var Future<mixed>|null */
    private ?Future $response = null;

    private bool $stopped = false;

    public function __construct(
        private Agent $agent,
        private readonly Commands $commands,
        private readonly Sessions $sessions,
        private readonly InputHistory $inputHistory,
        string $title = 'Neuron AI',
        string $subtitle = 'Agent conversation',
        ?TerminalInterface $terminal = null,
        ?string $figlet = null,
        string $figletFont = 'standard',
    ) {
        $this->terminal = $terminal ?? new Terminal();
        $this->view = new ConversationView(
            $this->terminal,
            $title,
            $subtitle,
            $this->commands->all(),
            $figlet,
            $figletFont,
        );
        $this->workingIndicator = $this->view->workingIndicator();
        $this->turns = new TurnQueue();
        $this->agentTurn = new AgentTurn($this->view);
        $this->view->showHistory(
            $this->agent->getChatHistory()->getMessages(),
        );
        $this->view->onSubmit($this->submit(...));
        $this->view->onDraftChange($this->inputHistory->leave(...));
        $this->view->onInput($this->handleInput(...));
        $this->view->onTick($this->tick(...));
    }

    public function run(): void
    {
        if (
            $this->terminal instanceof Terminal
            && (
                !stream_isatty(STDIN)
                || !stream_isatty(STDOUT)
            )
        ) {
            throw new \RuntimeException(
                'Neuron TUI requires an interactive TTY.',
            );
        }

        $this->view->run();
    }

    private function submit(SubmitEvent $event): void
    {
        if ($this->stopped) {
            return;
        }

        $this->inputHistory->leave();

        if ($event->isBlank()) {
            return;
        }

        $this->inputHistory->record($event->getValue());
        $submission = Submission::interpret($event->getValue());

        if ($submission instanceof CommandInput) {
            $this->commands->run(
                $submission->name,
                $submission->arguments,
                new TuiAdapter($this, $this->view, $this->commands, $this->sessions),
            );

            return;
        }

        $this->send($submission);
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

    private function handleInput(InputEvent $event): void
    {
        $keys = new Keybindings([
            'quit' => [Key::ctrl('c')],
            'recall-older-input' => [Key::UP],
            'recall-newer-input' => [Key::DOWN],
            'scroll-up' => [Key::PAGE_UP],
            'scroll-down' => [Key::PAGE_DOWN],
        ]);

        if ($keys->matches($event->getData(), 'quit')) {
            $event->stopPropagation();
            $this->stop();

            return;
        }

        // While a person is choosing from a list, the list owns the keys
        // that move through it, page keys included.
        if ($this->view->isChoosing()) {
            return;
        }

        if ($keys->matches($event->getData(), 'recall-older-input')) {
            if (
                $this->inputHistory->isNavigating()
                || $this->view->isComposerEmpty()
            ) {
                $input = $this->inputHistory->older();

                if ($input !== null) {
                    $event->stopPropagation();
                    $this->view->recallInput($input);
                }
            }

            return;
        }

        if (
            $keys->matches($event->getData(), 'recall-newer-input')
            && $this->inputHistory->isNavigating()
        ) {
            $input = $this->inputHistory->newer();

            if ($input !== null) {
                $event->stopPropagation();
                $this->view->recallInput($input);
            }

            return;
        }

        if ($keys->matches($event->getData(), 'scroll-up')) {
            $event->stopPropagation();
            $this->view->scrollUp();

            return;
        }

        if ($keys->matches($event->getData(), 'scroll-down')) {
            $event->stopPropagation();
            $this->view->scrollDown();
        }
    }
}
