<?php

declare(strict_types=1);

namespace NeuronCli;

use Amp\Future;
use NeuronAI\Agent\Agent;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronCli\Conversation\AgentTurn;
use NeuronCli\Conversation\MessageForAgent;
use NeuronCli\Conversation\SlashCommand;
use NeuronCli\Conversation\Submission;
use NeuronCli\Conversation\TurnQueue;
use NeuronCli\Conversation\UnknownSlashCommand;
use NeuronCli\Session\FileSessionStore;
use NeuronCli\Session\SessionStore;
use NeuronCli\Tui\ConversationView;
use NeuronCli\Tui\WorkingIndicator;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Terminal\Terminal;
use Symfony\Component\Tui\Terminal\TerminalInterface;
use Throwable;

use function Amp\async;

final class NeuronCli
{
    private readonly TerminalInterface $terminal;

    private readonly ConversationView $view;

    private readonly WorkingIndicator $workingIndicator;

    private readonly SessionStore $sessionStore;

    private readonly TurnQueue $turns;

    private readonly AgentTurn $agentTurn;

    /** @var Future<mixed>|null */
    private ?Future $response = null;

    public function __construct(
        private readonly Agent $agent,
        string $title = 'Neuron AI',
        string $subtitle = 'Agent conversation',
        ?TerminalInterface $terminal = null,
        ?SessionStore $sessionStore = null,
    ) {
        $this->terminal = $terminal ?? new Terminal();
        $this->sessionStore = $sessionStore ?? new FileSessionStore();
        $this->view = new ConversationView(
            $this->terminal,
            $title,
            $subtitle,
        );
        $this->workingIndicator = $this->view->workingIndicator();
        $this->turns = new TurnQueue();
        $this->agentTurn = new AgentTurn($this->agent, $this->view);
        $this->view->showHistory(
            $this->agent->getChatHistory()->getMessages(),
        );
        $this->view->onSubmit($this->submit(...));
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
                'Neuron CLI requires an interactive TTY.',
            );
        }

        $this->view->run();
    }

    private function submit(SubmitEvent $event): void
    {
        if ($event->isBlank()) {
            return;
        }

        $submission = Submission::interpret($event->getValue());

        if ($submission instanceof SlashCommand) {
            $this->carryOut($submission);

            return;
        }

        if ($submission instanceof UnknownSlashCommand) {
            $this->view->showUnknownSlashCommand($submission->name);

            return;
        }

        $this->send($submission);
    }

    private function send(MessageForAgent $message): void
    {
        $accepted = $this->turns->accept($message->contents);

        if ($accepted === null) {
            $this->showQueue();

            return;
        }

        $this->beginTurn($accepted);
    }

    /**
     * A command that changes Session cannot run mid-turn: an answer already on
     * its way would land in the conversation that replaced the one it belongs
     * to. Leaving the TUI is never refused.
     */
    private function carryOut(SlashCommand $command): void
    {
        if ($command === SlashCommand::Exit) {
            $this->view->stop();

            return;
        }

        if ($this->turns->isBusy()) {
            $this->view->showError(
                $command->value
                    . ' is refused while the Agent is working. '
                    . 'Try it again once the turn has finished.',
            );

            return;
        }

        match ($command) {
            SlashCommand::Clear => $this->openSession(),
            SlashCommand::Sessions => $this->view->showError(
                'The Session picker is not available yet.',
            ),
        };
    }

    /**
     * Puts a Session on the Agent and shows it, screen and composer both.
     *
     * The conversation it replaces is left where the store keeps it: nothing
     * here ever deletes a stored Session.
     */
    private function openSession(): void
    {
        $session = $this->sessionStore->open();
        $this->agent->setChatHistory($session);
        $this->view->showHistory($session->getMessages());
        $this->view->emptyComposer();
    }

    private function tick(): bool
    {
        $message = $this->turns->beginWorking();

        if ($message !== null) {
            $this->response = async(function () use ($message): void {
                $this->agentTurn->respond($message);
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
            $this->view->showError(
                $exception::class . ': ' . $exception->getMessage(),
            );
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

    private function finishTurn(): void
    {
        $this->workingIndicator->stop();
        $this->view->ready();
    }

    private function showQueue(): void
    {
        $this->view->showQueuedMessages($this->turns->queued());
    }

    private function handleInput(InputEvent $event): void
    {
        $keys = new Keybindings([
            'quit' => [Key::ctrl('c')],
            'scroll-up' => [Key::PAGE_UP],
            'scroll-down' => [Key::PAGE_DOWN],
        ]);

        if ($keys->matches($event->getData(), 'quit')) {
            $event->stopPropagation();
            $this->view->stop();

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
