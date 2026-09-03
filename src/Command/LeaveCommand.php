<?php

declare(strict_types=1);

namespace NeuronTui\Command;

use NeuronTui\Conversation\ConcurrentControls;

/**
 * Leaves the terminal.
 *
 * One of the commands Neuron TUI ships: a Host Application mounts it the way
 * it mounts one of its own, under `/exit` or under whatever name it prefers.
 * A Host Application that mounts nothing leaves `Ctrl+C` as the only way out.
 *
 * It is Concurrent: leaving asks its question earlier than the answer on its
 * way can spoil, so it receives only the Controls that remain meaningful
 * while a Turn is under way.
 */
final readonly class LeaveCommand implements ConcurrentCommand
{
    /**
     * @param string $name the name it answers to, slash included
     */
    public function __construct(private string $name = '/exit')
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function describe(): string
    {
        return 'Closes the Conversation TUI.';
    }

    public function run(ConcurrentControls $controls, string $arguments): void
    {
        $controls->stop();
    }
}
