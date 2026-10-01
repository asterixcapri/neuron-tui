<?php

declare(strict_types=1);

namespace NeuronTui;

use InvalidArgumentException;
use LogicException;
use NeuronAI\Agent\Agent;
use NeuronChatCore\Command\Commands;
use NeuronChatCore\Configuration\ConfigurationStore;
use NeuronChatCore\Conversation\ConversationRuntime as CoreRuntime;
use NeuronChatCore\InputHistory\InputHistory;
use NeuronChatCore\Session\SessionStore;
use NeuronChatCore\Storage\InMemoryStorage;
use NeuronTui\Conversation\ConversationInputHandler;
use NeuronTui\Conversation\ConversationRuntime;
use NeuronTui\Session\SessionTitleGeneration;
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

    private readonly Commands $commands;

    private readonly SessionStore $sessionStore;

    private readonly ConfigurationStore $configurationStore;

    private readonly InputHistory $inputHistory;

    private bool $started = false;


    public function __construct(
        private readonly CoreRuntime $conversation,
        private readonly ?TerminalInterface $terminal = null,
        ?Commands $commands = null,
        ?ConfigurationStore $configurationStore = null,
        ?InputHistory $inputHistory = null,
    ) {
        $this->commands = $commands ?? new Commands();
        $this->sessionStore = $this->conversation->sessionStore();
        $this->configurationStore = $configurationStore ?? new ConfigurationStore(new InMemoryStorage(), 'local');
        $this->inputHistory = $inputHistory ?? new InputHistory(new InMemoryStorage());
    }

    public static function make(
        CoreRuntime $conversation,
        ?TerminalInterface $terminal = null,
        ?Commands $commands = null,
        ?ConfigurationStore $configurationStore = null,
        ?InputHistory $inputHistory = null,
    ): self {
        return new self(
            $conversation,
            $terminal,
            $commands,
            $configurationStore,
            $inputHistory,
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

    /** The Agent currently answering, including a copy bound to a selected Session. */
    public function agent(): Agent
    {
        return $this->conversation->agent();
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
            $this->conversation->userMessageProcessors(),
        );
        $sessionTitleGeneration = new SessionTitleGeneration();
        $runtime = new ConversationRuntime(
            $this->conversation,
            $view,
            $sessionTitleGeneration,
        );
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
            throw new RuntimeException(
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
