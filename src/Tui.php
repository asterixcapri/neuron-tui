<?php

declare(strict_types=1);

namespace NeuronTui;

use InvalidArgumentException;
use LogicException;
use NeuronAI\Agent\Agent;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\InputHistory\InputHistory;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronTui\Conversation\ConversationInputHandler;
use NeuronTui\Conversation\ConversationRuntime;
use NeuronTui\View\ConversationView;
use Symfony\Component\Tui\Terminal\Terminal;
use Symfony\Component\Tui\Terminal\TerminalInterface;

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

    private readonly Commands $commands;

    private readonly SessionStore $sessionStore;

    private readonly ConfigurationStore $configurationStore;

    private readonly InputHistory $inputHistory;

    private bool $started = false;

    public function __construct(
        private readonly Agent $agent,
        private readonly ?TerminalInterface $terminal = null,
        ?Commands $commands = null,
        ?SessionStore $sessionStore = null,
        ?ConfigurationStore $configurationStore = null,
        ?InputHistory $inputHistory = null,
    ) {
        $this->commands = $commands ?? new Commands();
        $this->sessionStore = $sessionStore ?? new SessionStore(
            new InMemoryStorage(),
            'local',
        );
        $this->configurationStore = $configurationStore ?? new ConfigurationStore(new InMemoryStorage(), 'local');
        $this->inputHistory = $inputHistory ?? new InputHistory(new InMemoryStorage());
    }

    public static function make(
        Agent $agent,
        ?TerminalInterface $terminal = null,
        ?Commands $commands = null,
        ?SessionStore $sessionStore = null,
        ?ConfigurationStore $configurationStore = null,
        ?InputHistory $inputHistory = null,
    ): self {
        return new self($agent, $terminal, $commands, $sessionStore, $configurationStore, $inputHistory);
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

    public function setFiglet(
        string $text,
        string $font = 'standard',
    ): self {
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
        $view = new ConversationView(
            $terminal,
            $this->title,
            $this->subtitle,
            $this->commands->all(),
            $this->figlet,
            $this->figletFont,
        );
        $runtime = new ConversationRuntime($this->agent, $view);
        $input = new ConversationInputHandler(
            $view,
            $this->inputHistory,
            $runtime,
            $this->commands,
            $this->sessionStore,
            $this->configurationStore,
        );
        $runtime->synchronizeHistory();
        $view->onSubmit($input->handleSubmit(...));
        $view->onDraftChange($input->handleDraftChange(...));
        $view->onInput($input->handleInput(...));
        $view->onTick($runtime->tick(...));

        if (
            $terminal instanceof Terminal
            && (
                !stream_isatty(STDIN)
                || !stream_isatty(STDOUT)
            )
        ) {
            throw new \RuntimeException(
                'Neuron TUI requires an interactive TTY.',
            );
        }

        $view->run();
    }

    private function ensureNotStarted(): void
    {
        if ($this->started) {
            throw new LogicException('A TUI instance can only be configured and run once.');
        }
    }
}
