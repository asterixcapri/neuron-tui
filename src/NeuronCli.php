<?php

declare(strict_types=1);

namespace NeuronCli;

use Amp\Future;
use InvalidArgumentException;
use NeuronAI\Agent\Agent;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronCli\Conversation\AgentTurn;
use NeuronCli\Conversation\BuiltInCommand;
use NeuronCli\Conversation\Controls;
use NeuronCli\Conversation\MessageForAgent;
use NeuronCli\Conversation\SlashCommand;
use NeuronCli\Conversation\SlashCommandInput;
use NeuronCli\Conversation\Submission;
use NeuronCli\Conversation\TurnQueue;
use NeuronCli\Session\InMemorySessionProvider;
use NeuronCli\Session\Session;
use NeuronCli\Session\SessionProvider;
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

    private readonly SessionProvider $sessionProvider;

    private readonly TurnQueue $turns;

    private readonly AgentTurn $agentTurn;

    /**
     * The mounted commands, indexed by the name each answers to.
     *
     * @var array<string, SlashCommand>
     */
    private readonly array $commands;

    /** @var Future<mixed>|null */
    private ?Future $response = null;

    /**
     * @param list<SlashCommand> $commands what can be typed here besides the
     *                                     commands the TUI carries out itself
     */
    public function __construct(
        private readonly Agent $agent,
        string $title = 'Neuron AI',
        string $subtitle = 'Agent conversation',
        ?TerminalInterface $terminal = null,
        ?SessionProvider $sessionProvider = null,
        array $commands = [],
    ) {
        $this->commands = self::mount($commands);
        $this->terminal = $terminal ?? new Terminal();
        // A Host Application that named no provider named no place for its
        // conversations either, so they are kept in memory and nothing is
        // written anywhere.
        $this->sessionProvider = $sessionProvider
            ?? new InMemorySessionProvider();
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
        $this->view->onSessionChosen($this->resumeSession(...));
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

        if ($submission instanceof SlashCommandInput) {
            $this->carryOut($submission);

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
     * Carries out the command the typed name points at, with its arguments.
     *
     * The name is looked for among the commands a Host Application mounted
     * first, and then among the ones the TUI carries out itself; a name
     * neither answers is said in the conversation rather than sent to the
     * Agent, because the Slash namespace is answered locally.
     *
     * No command runs mid-turn but leaving: an answer already on its way
     * would land in a conversation the command had meanwhile replaced.
     */
    private function carryOut(SlashCommandInput $input): void
    {
        $mounted = $this->commands[$input->name] ?? null;

        if ($mounted !== null) {
            if ($this->refusedWhileWorking($input->name)) {
                return;
            }

            // The command was taken, so what was typed leaves the composer:
            // only a name nobody answers stays there to be corrected.
            $this->view->emptyComposer();
            $this->runSafely($mounted, $input->arguments);

            return;
        }

        $command = BuiltInCommand::tryFrom($input->name);

        if ($command === null) {
            $this->view->showUnknownSlashCommand($input->name);

            return;
        }

        if ($command === BuiltInCommand::Exit) {
            $this->view->stop();

            return;
        }

        if ($this->refusedWhileWorking($command->value)) {
            return;
        }

        match ($command) {
            BuiltInCommand::Clear => $this->startSession(),
            BuiltInCommand::Sessions => $this->chooseSession(),
        };
    }

    /**
     * Runs a command of the Host Application's, and survives it.
     *
     * From here on the Conversation TUI carries out code that is not its own,
     * so whatever a command lets rise becomes a line of error in the
     * conversation, as an exception during a Turn already does, and the
     * terminal stays where the person left it.
     */
    private function runSafely(SlashCommand $command, string $arguments): void
    {
        $controls = new Controls(
            $this->view,
            $this->agent,
            function (string $prompt): void {
                $this->send(new MessageForAgent($prompt));
            },
        );

        try {
            $command->run($controls, $arguments);
        } catch (Throwable $exception) {
            $this->showFailure($exception);
        }
    }

    /**
     * Turns a command away while the Agent is answering, and says so.
     *
     * A command that changed the conversation mid-turn would have an answer
     * already on its way land where it does not belong, so the rule is the
     * TUI's rather than any single command's, and leaving — which asks the
     * question earlier — is the one thing it does not cover.
     *
     * Returns whether the command was turned away.
     */
    private function refusedWhileWorking(string $name): bool
    {
        if (!$this->turns->isBusy()) {
            return false;
        }

        $this->view->showError(
            $name
                . ' is refused while the Agent is working. '
                . 'Try it again once the turn has finished.',
        );

        return true;
    }

    /**
     * Indexes the commands by the name each answers to.
     *
     * Two commands answering to the same name are a mistake in how the
     * terminal was built, so it is said at once instead of one of them
     * silently winning. The names the TUI carries out itself are taken too,
     * and are said apart, because there is no second command to look for.
     *
     * @param list<SlashCommand> $commands
     *
     * @return array<string, SlashCommand>
     */
    private static function mount(array $commands): array
    {
        $mounted = [];

        foreach ($commands as $command) {
            $name = $command->name();

            if (BuiltInCommand::tryFrom($name) instanceof BuiltInCommand) {
                throw new InvalidArgumentException(
                    $name
                        . ' is a Slash command the Conversation TUI '
                        . 'answers to itself.',
                );
            }

            if (isset($mounted[$name])) {
                throw new InvalidArgumentException(
                    'Two Slash commands answer to ' . $name . '.',
                );
            }

            $mounted[$name] = $command;
        }

        return $mounted;
    }

    /**
     * Offers the Sessions of this Agent for a person to return to one.
     *
     * A list with nothing in it is not worth entering, so it is said in the
     * conversation instead.
     */
    private function chooseSession(): void
    {
        $sessions = $this->sessionProvider->list();

        if ($sessions === []) {
            $this->view->showError(
                'There is no earlier Session to return to yet.',
            );

            return;
        }

        $this->view->showSessions($sessions);
    }

    /**
     * Puts a freshly minted Session on the Agent.
     *
     * Minting one and opening it are the provider's two separate operations,
     * and a new Session is both: the key comes back from the provider and
     * goes straight back to it.
     */
    private function startSession(): void
    {
        $this->openSession($this->sessionProvider->create()->key);
    }

    /**
     * Puts the Session a person chose out of the picker on the Agent.
     *
     * The picker hands back the Session, key and all, so the key goes from
     * the provider's own description of a Session straight back to the
     * provider.
     */
    private function resumeSession(Session $session): void
    {
        $this->openSession($session->key);
    }

    /**
     * Puts the Session with the given key on the Agent and shows it, screen
     * and composer both.
     *
     * The key is one the provider minted, because no other origin exists.
     * The conversation it replaces is left where the provider keeps it:
     * nothing here ever deletes a stored Session.
     */
    private function openSession(string $key): void
    {
        $session = $this->sessionProvider->open($key);
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

        // While a person is choosing a Session the list owns the keys that
        // move through it, page keys included.
        if ($this->view->isChoosingSession()) {
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
