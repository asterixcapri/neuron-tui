<?php

declare(strict_types=1);

namespace NeuronTui;

use InvalidArgumentException;
use LogicException;
use NeuronAI\Agent\Agent;
use NeuronTui\Command\Command;
use NeuronTui\Command\CommandKit;
use NeuronTui\Command\RunsWhileWorking;
use NeuronTui\Command\SlashCommand;
use NeuronTui\Conversation\ConversationRuntime;
use Symfony\Component\Tui\Terminal\TerminalInterface;

/**
 * Configures and starts a Conversation TUI.
 */
final class Tui
{
    private string $title = 'Neuron AI';

    private string $subtitle = 'Agent conversation';

    /** @var list<SlashCommand|RunsWhileWorking> */
    private array $commands = [];

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

    /**
     * @param Command|CommandKit|array<array-key, mixed> $commands
     */
    public function addCommand(Command|CommandKit|array $commands): self
    {
        $this->ensureNotStarted();

        $commands = is_array($commands) ? $commands : [$commands];

        foreach ($commands as $command) {
            if (
                !$command instanceof Command
                && !$command instanceof CommandKit
            ) {
                throw new InvalidArgumentException(
                    'A TUI command must implement Command or CommandKit.',
                );
            }

            $members = $command instanceof CommandKit
                ? $command->commands()
                : [$command];

            foreach ($members as $member) {
                if (
                    !$member instanceof SlashCommand
                    && !$member instanceof RunsWhileWorking
                ) {
                    throw new InvalidArgumentException(
                        'A TUI command must be runnable as a Slash command.',
                    );
                }

                $this->commands[] = $member;
            }
        }

        return $this;
    }

    public function run(): void
    {
        $this->ensureNotStarted();
        $this->started = true;

        (new ConversationRuntime(
            $this->agent,
            $this->title,
            $this->subtitle,
            $this->terminal,
            $this->commands,
        ))->run();
    }

    private function ensureNotStarted(): void
    {
        if ($this->started) {
            throw new LogicException('A TUI instance can only be configured and run once.');
        }
    }
}
