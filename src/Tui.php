<?php

declare(strict_types=1);

namespace NeuronTui;

use InvalidArgumentException;
use LogicException;
use NeuronAI\Agent\Agent;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Session\Sessions;
use NeuronInteraction\InputHistory\InputHistory;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronTui\Conversation\ConversationRuntime;
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

    private readonly Sessions $sessions;

    private readonly InputHistory $inputHistory;

    private bool $started = false;

    public function __construct(
        private readonly Agent $agent,
        private readonly ?TerminalInterface $terminal = null,
        ?Commands $commands = null,
        ?Sessions $sessions = null,
        ?InputHistory $inputHistory = null,
    ) {
        $this->commands = $commands ?? new Commands();
        $this->sessions = $sessions ?? new Sessions(new InMemoryStorage());
        $this->inputHistory = $inputHistory ?? new InputHistory(new InMemoryStorage());
    }

    public static function make(
        Agent $agent,
        ?TerminalInterface $terminal = null,
        ?Commands $commands = null,
        ?Sessions $sessions = null,
        ?InputHistory $inputHistory = null,
    ): self {
        return new self($agent, $terminal, $commands, $sessions, $inputHistory);
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

        $runtime = new ConversationRuntime(
            $this->agent,
            $this->commands,
            $this->sessions,
            $this->inputHistory,
            $this->title,
            $this->subtitle,
            $this->terminal,
            $this->figlet,
            $this->figletFont,
        );
        $runtime->run();
    }

    private function ensureNotStarted(): void
    {
        if ($this->started) {
            throw new LogicException('A TUI instance can only be configured and run once.');
        }
    }
}
