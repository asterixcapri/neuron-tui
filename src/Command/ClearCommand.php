<?php

declare(strict_types=1);

namespace NeuronTui\Command;

/**
 * Starts a new Session, leaving the one on screen where it is stored.
 *
 * One of the commands Neuron TUI ships: a Host Application mounts it the way
 * it mounts one of its own, under `/clear` or under whatever name it prefers.
 *
 * Starting a Session returns the empty History the Agent needs. Minting its
 * key stays behind the Sessions seam, and nothing here ever deletes what the
 * new Session replaced.
 */
final readonly class ClearCommand implements CommandInterface
{
    /** @param string $name the name it answers to, slash omitted */
    public function __construct(private string $name = 'clear')
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function describe(): string
    {
        return 'Starts a new Session, leaving the current one stored.';
    }

    public function run(CommandControls $controls, CommandArguments $arguments): void
    {
        $controls->agent()->setChatHistory($controls->sessions()->start());
    }
}
