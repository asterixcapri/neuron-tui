<?php

declare(strict_types=1);

namespace NeuronCli;

use Amp\Future;
use InvalidArgumentException;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronCli\Conversation\AgentTurn;
use NeuronCli\Conversation\Command;
use NeuronCli\Conversation\CommandKit;
use NeuronCli\Conversation\Controls;
use NeuronCli\Conversation\LimitedControls;
use NeuronCli\Conversation\MessageForAgent;
use NeuronCli\Conversation\RunsWhileWorking;
use NeuronCli\Conversation\SlashCommand;
use NeuronCli\Conversation\SlashCommandInput;
use NeuronCli\Conversation\Submission;
use NeuronCli\Conversation\TurnQueue;
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

    private readonly TurnQueue $turns;

    private readonly AgentTurn $agentTurn;

    /**
     * The mounted commands, indexed by the name each answers to.
     *
     * @var array<string, SlashCommand|RunsWhileWorking>
     */
    private readonly array $commands;

    /** @var Future<mixed>|null */
    private ?Future $response = null;

    /**
     * @param list<SlashCommand|RunsWhileWorking|CommandKit> $commands
     *     everything that can be typed here after a slash: the Conversation
     *     TUI mounts nothing on its own, and a kit stands for the commands it
     *     carries
     */
    public function __construct(
        private Agent $agent,
        string $title = 'Neuron AI',
        string $subtitle = 'Agent conversation',
        ?TerminalInterface $terminal = null,
        array $commands = [],
    ) {
        $this->commands = self::mount($commands);
        $this->terminal = $terminal ?? new Terminal();
        $this->view = new ConversationView(
            $this->terminal,
            $title,
            $subtitle,
        );
        $this->workingIndicator = $this->view->workingIndicator();
        $this->turns = new TurnQueue();
        $this->agentTurn = new AgentTurn($this->view);
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
     * The name is looked for among the commands mounted when the terminal was
     * built, and there is nowhere else to look: the Conversation TUI answers
     * to nothing on its own. A name no command answers to is said in the
     * conversation rather than sent to the Agent, because the Slash namespace
     * is answered locally.
     *
     * Whether a command runs while the Agent is working is read from its
     * type: an answer already on its way would land in a conversation a
     * command had meanwhile replaced, so only what says it runs there does.
     */
    private function carryOut(SlashCommandInput $input): void
    {
        $command = $this->commands[$input->name] ?? null;

        if ($command === null) {
            $this->view->showUnknownSlashCommand($input->name);

            return;
        }

        if (
            !$command instanceof RunsWhileWorking
            && $this->refusedWhileWorking($input->name)
        ) {
            return;
        }

        // The command was taken, so what was typed leaves the composer:
        // only a name nobody answers stays there to be corrected.
        $this->view->emptyComposer();
        $this->runSafely($command, $input->arguments);
    }

    /**
     * Runs a command of the Host Application's, and survives it.
     *
     * From here on the Conversation TUI carries out code that is not its own,
     * so whatever a command lets rise becomes a line of error in the
     * conversation, as an exception during a Turn already does, and the
     * terminal stays where the person left it.
     *
     * It runs where it was typed, which is a callback of the event loop the
     * TUI and amphp share, and a callback runs in a fiber of its own. So a
     * command that waits — `choose()` is the one verb that does — suspends
     * that fiber alone: the loop goes on ticking, painting and reading keys
     * meanwhile, which is what lets a person answer the list.
     */
    private function runSafely(
        SlashCommand|RunsWhileWorking $command,
        string $arguments,
    ): void {
        $conversation = $this->agent->getChatHistory();
        $failure = null;

        try {
            if ($command instanceof RunsWhileWorking) {
                $command->run($this->limitedControls(), $arguments);
            } else {
                $command->run($this->controls(), $arguments);
            }
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        // The screen is reconciled before anything is said about the run, so
        // that a command which failed after changing conversation leaves its
        // line of error on the conversation it left behind.
        $this->reconcile($conversation);

        if ($failure instanceof Throwable) {
            $this->showFailure($failure);
        }
    }

    /**
     * Everything a command that expects the Agent to stand still may do.
     */
    private function controls(): Controls
    {
        return new Controls(
            $this->view,
            fn (): Agent => $this->agent,
            function (string $prompt): void {
                $this->send(new MessageForAgent($prompt));
            },
            $this->answerFrom(...),
            $this->mounted(),
        );
    }

    /**
     * The fewer verbs a command running while the Agent works is handed.
     *
     * There is nothing to close off here: what is missing is missing from the
     * type, so a command asking for a Picker or for the Agent was already
     * stopped where it was written.
     */
    private function limitedControls(): LimitedControls
    {
        return new LimitedControls($this->view, $this->mounted());
    }

    /**
     * The mounted commands, in the order the Host Application named them.
     *
     * @return list<Command>
     */
    private function mounted(): array
    {
        return array_values($this->commands);
    }

    /**
     * Puts back on screen the conversation the Agent is holding now.
     *
     * Read after every command, so that whatever it did — opened another
     * Session, put another Agent in charge, installed a History of its own —
     * the screen agrees with the Agent without the command having to say so.
     *
     * A command that left the History where it found it left the screen alone
     * too: what it said, warned or asked stays where it was written, and
     * repainting would throw it away. Handing a History from one Agent to
     * another is that same case, which is why nothing is repainted after a
     * change of Agent alone.
     */
    private function reconcile(ChatHistoryInterface $conversation): void
    {
        $current = $this->agent->getChatHistory();

        if ($current === $conversation) {
            return;
        }

        $this->view->showHistory($current->getMessages());
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
    private function answerFrom(Agent $agent): void
    {
        $agent->setChatHistory($this->agent->getChatHistory());
        $this->agent = $agent;
    }

    /**
     * Turns a command away while the Agent is answering, and says so.
     *
     * A command that changed the conversation mid-turn would have an answer
     * already on its way land where it does not belong, so the rule is the
     * TUI's rather than any single command's: what says in its type that it
     * runs while the Agent works never reaches here.
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
     * silently winning. No name is reserved: the commands Neuron CLI ships
     * are mounted here like any other, and a Host Application is free to
     * leave any of them out.
     *
     * A kit is unrolled before any of this, so a command that arrived in one
     * is weighed here like any other.
     *
     * @param list<SlashCommand|RunsWhileWorking|CommandKit> $commands
     *
     * @return array<string, SlashCommand|RunsWhileWorking>
     */
    private static function mount(array $commands): array
    {
        $mounted = [];

        foreach (self::unroll($commands) as $command) {
            $name = $command->name();

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
     * Puts the commands a kit carries where the kit was named.
     *
     * This is the one place a kit is opened, and nothing downstream is told
     * a kit ever existed.
     *
     * @param list<SlashCommand|RunsWhileWorking|CommandKit> $commands
     *
     * @return list<SlashCommand|RunsWhileWorking>
     */
    private static function unroll(array $commands): array
    {
        $unrolled = [];

        foreach ($commands as $command) {
            if ($command instanceof CommandKit) {
                $unrolled = [...$unrolled, ...$command->commands()];

                continue;
            }

            $unrolled[] = $command;
        }

        return $unrolled;
    }

    private function tick(): bool
    {
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

        // While a person is choosing from a list, the list owns the keys
        // that move through it, page keys included.
        if ($this->view->isChoosing()) {
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
