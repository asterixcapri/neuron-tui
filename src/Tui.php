<?php

declare(strict_types=1);

namespace NeuronTui;

use InvalidArgumentException;
use LogicException;
use NeuronAI\Agent\Agent;
use NeuronTui\Command\CommandInterface;
use NeuronTui\Command\CommandKitInterface;
use NeuronTui\Command\ConcurrentCommandInterface;
use NeuronTui\Conversation\ConversationRuntime;
use NeuronTui\Storage\StorageInterface;
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

    /** @var list<CommandInterface|ConcurrentCommandInterface> */
    private array $commands = [];

    private ?StorageInterface $storage = null;

    private bool $started = false;

    public function __construct(
        private readonly Agent $agent,
        private readonly ?TerminalInterface $terminal = null,
    ) {}

    public static function make(
        Agent $agent,
        ?TerminalInterface $terminal = null,
    ): self {
        return new self($agent, $terminal);
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

    /**
     * Configures the storage used by this terminal's Sessions.
     */
    public function setStorage(StorageInterface $storage): self
    {
        $this->ensureNotStarted();
        $this->storage = $storage;

        return $this;
    }

    /**
     * @param CommandInterface|ConcurrentCommandInterface|CommandKitInterface|array<array-key, mixed> $commands
     */
    public function addCommand(
        CommandInterface|ConcurrentCommandInterface|CommandKitInterface|array $commands,
    ): self
    {
        $this->ensureNotStarted();

        $commands = is_array($commands) ? $commands : [$commands];

        foreach ($commands as $command) {
            if (
                !$command instanceof CommandInterface
                && !$command instanceof ConcurrentCommandInterface
                && !$command instanceof CommandKitInterface
            ) {
                throw new InvalidArgumentException(
                    'A TUI command must implement CommandInterface, '
                        . 'ConcurrentCommandInterface or CommandKitInterface.',
                );
            }

            $members = $command instanceof CommandKitInterface
                ? $command->commands()
                : [$command];

            foreach ($members as $member) {
                $this->mount($member);
            }
        }

        return $this;
    }

    private function mount(mixed $command): void
    {
        if (
            !$command instanceof CommandInterface
            && !$command instanceof ConcurrentCommandInterface
        ) {
            throw new InvalidArgumentException(
                'A TUI command must implement CommandInterface '
                    . 'or ConcurrentCommandInterface.',
            );
        }

        $this->commands[] = $command;
    }

    public function run(): void
    {
        $this->ensureNotStarted();
        $this->started = true;

        $runtime = new ConversationRuntime(
            $this->agent,
            $this->title,
            $this->subtitle,
            $this->terminal,
            $this->commands,
            $this->figlet,
            $this->figletFont,
            $this->storage,
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
