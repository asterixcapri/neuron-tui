<?php

declare(strict_types=1);

namespace NeuronTui;

use InvalidArgumentException;
use LogicException;
use NeuronAI\Agent\Agent;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Agent\AgentFactoryRegistry;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\InputHistory\InputHistory;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronTui\Conversation\ConversationInput;
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

    private readonly InputHistory $inputHistory;

    private readonly Agent $agent;

    private readonly ConfigurationStore $configurationStore;

    private bool $started = false;

    public function __construct(
        private readonly AgentFactoryRegistry $agentFactoryRegistry,
        private readonly string $initialAgentIdentifier,
        private readonly ?TerminalInterface $terminal = null,
        ?Commands $commands = null,
        ?SessionStore $sessionStore = null,
        ?InputHistory $inputHistory = null,
        ?ConfigurationStore $configurationStore = null,
    ) {
        $this->configurationStore = $configurationStore ?? new ConfigurationStore(new InMemoryStorage(), 'local');
        $this->agent = $this->agentFactoryRegistry->create($this->initialAgentIdentifier, $this->configurationStore);
        $this->commands = $commands ?? new Commands();
        $this->sessionStore = $sessionStore ?? new SessionStore(
            new InMemoryStorage(),
            'local',
        );
        $this->inputHistory = $inputHistory ?? new InputHistory(new InMemoryStorage());
    }

    public static function make(
        AgentFactoryRegistry $agentFactoryRegistry,
        string $initialAgentIdentifier,
        ?TerminalInterface $terminal = null,
        ?Commands $commands = null,
        ?SessionStore $sessionStore = null,
        ?InputHistory $inputHistory = null,
        ?ConfigurationStore $configurationStore = null,
    ): self {
        return new self(
            $agentFactoryRegistry,
            $initialAgentIdentifier,
            $terminal,
            $commands,
            $sessionStore,
            $inputHistory,
            $configurationStore,
        );
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
        $input = new ConversationInput(
            $view,
            $this->inputHistory,
            $runtime,
            $this->commands,
            $this->sessionStore,
            $this->agentFactoryRegistry,
            $this->configurationStore,
            $this->initialAgentIdentifier,
        );
        $view->showHistory($this->agent->getChatHistory()->getMessages());
        $view->onSubmit($input->submit(...));
        $view->onDraftChange($input->draftChanged(...));
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
