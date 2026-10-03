<?php

declare(strict_types=1);

namespace NeuronTui;

use InvalidArgumentException;
use LogicException;
use NeuronAI\Agent\Agent;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Conversation;
use NeuronInteraction\InputHistory\InputHistory;
use NeuronInteraction\Interruption\StopSignal;
use NeuronInteraction\Message\UserMessageProcessorInterface;
use NeuronInteraction\Message\UserMessageProcessors;
use NeuronInteraction\Session\Session;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronTui\Input\InputHandler;
use NeuronTui\Turn\TurnScheduler;
use NeuronTui\View\ConversationView;
use RuntimeException;
use Symfony\Component\Tui\Terminal\Terminal;
use Symfony\Component\Tui\Terminal\TerminalInterface;

use function implode;
use function in_array;
use function sprintf;
use function stream_isatty;

use const STDIN;
use const STDOUT;

/**
 * Configures and starts a Conversation TUI.
 */
final class Tui
{
    private const array FIGLET_FONTS = [
        'standard',
        'big',
        'small',
        'slant',
        'mini',
    ];

    private string $title = 'Neuron AI';

    private string $subtitle = 'Agent conversation';

    private ?string $figlet = null;

    private string $figletFont = 'standard';

    private SessionStore $sessionStore;

    private ?Session $session = null;

    private ?StopSignal $stopSignal = null;

    private UserMessageProcessorInterface $userMessageProcessors;

    private ?TerminalInterface $terminal = null;

    private Commands $commands;

    private ConfigurationStore $configurationStore;

    private InputHistory $inputHistory;

    private bool $started = false;

    public function __construct(
        private readonly Agent $agent,
    ) {
        $this->sessionStore = new SessionStore(new InMemoryStorage(), 'local');
        $this->commands = new Commands();
        $this->configurationStore = new ConfigurationStore(new InMemoryStorage(), 'local');
        $this->inputHistory = new InputHistory(new InMemoryStorage());
        $this->userMessageProcessors = new UserMessageProcessors();
    }

    public static function make(Agent $agent): self
    {
        return new self($agent);
    }

    public function setSessionStore(SessionStore $sessionStore): self
    {
        $this->ensureNotStarted();
        $this->sessionStore = $sessionStore;

        return $this;
    }

    public function setSession(Session $session): self
    {
        $this->ensureNotStarted();
        $this->session = $session;

        return $this;
    }

    public function setStopSignal(StopSignal $stopSignal): self
    {
        $this->ensureNotStarted();
        $this->stopSignal = $stopSignal;

        return $this;
    }

    public function setUserMessageProcessors(UserMessageProcessorInterface $userMessageProcessors): self
    {
        $this->ensureNotStarted();
        $this->userMessageProcessors = $userMessageProcessors;

        return $this;
    }

    public function setTerminal(TerminalInterface $terminal): self
    {
        $this->ensureNotStarted();
        $this->terminal = $terminal;

        return $this;
    }

    public function setCommands(Commands $commands): self
    {
        $this->ensureNotStarted();
        $this->commands = $commands;

        return $this;
    }

    public function setConfigurationStore(ConfigurationStore $configurationStore): self
    {
        $this->ensureNotStarted();
        $this->configurationStore = $configurationStore;

        return $this;
    }

    public function setInputHistory(InputHistory $inputHistory): self
    {
        $this->ensureNotStarted();
        $this->inputHistory = $inputHistory;

        return $this;
    }

    public function setTitle(string $title): self
    {
        $this->ensureNotStarted();
        $this->title = $title;

        return $this;
    }

    public function setSubtitle(string $subtitle): self
    {
        $this->ensureNotStarted();
        $this->subtitle = $subtitle;

        return $this;
    }

    public function setFiglet(string $text, string $font = 'standard'): self
    {
        $this->ensureNotStarted();

        if (!in_array($font, self::FIGLET_FONTS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown FIGlet font "%s". Available fonts: %s.',
                $font,
                implode(', ', self::FIGLET_FONTS),
            ));
        }

        $this->figlet = $text;
        $this->figletFont = $font;

        return $this;
    }

    public function run(): void
    {
        $this->ensureNotStarted();
        $this->started = true;

        $terminal = $this->terminal ?? new Terminal();
        $hasInteractiveTty = stream_isatty(STDIN) && stream_isatty(STDOUT);

        if ($terminal instanceof Terminal && !$hasInteractiveTty) {
            throw new RuntimeException(
                'Neuron TUI requires an interactive TTY.',
            );
        }

        $admitCommand = static fn(CommandInterface $command): bool => true;
        $conversation = new Conversation(
            $this->agent,
            $this->sessionStore,
            session: $this->session,
            stopSignal: $this->stopSignal,
            userMessageProcessors: $this->userMessageProcessors,
            commands: $this->commands,
            configurationStore: $this->configurationStore,
            admitCommand: static function (CommandInterface $command) use (&$admitCommand): bool {
                return $admitCommand($command);
            },
        );
        $view = new ConversationView(
            $terminal,
            $this->title,
            $this->subtitle,
            $this->commands,
            $this->figlet,
            $this->figletFont,
            $this->userMessageProcessors,
        );
        $scheduler = new TurnScheduler(
            $conversation,
            $view,
        );
        $admitCommand = $scheduler->admitCommand(...);
        $input = new InputHandler(
            $view,
            $this->inputHistory,
            $scheduler,
        );
        $scheduler->synchronizeHistory();
        $view->onSubmit($input->handleSubmit(...));
        $view->onDraftChange($input->handleDraftChange(...));
        $view->onInput($input->handleInput(...));
        $view->onTick($scheduler->tick(...));

        $view->run();
    }

    private function ensureNotStarted(): void
    {
        if ($this->started) {
            throw new LogicException('A TUI instance can only be configured and run once.');
        }
    }
}
