<?php

declare(strict_types=1);

namespace NeuronCli\Conversation\Commands;

use NeuronCli\Conversation\Controls;
use NeuronCli\Conversation\SlashCommand;

/**
 * Leaves the terminal.
 *
 * One of the commands Neuron CLI ships: a Host Application mounts it the way
 * it mounts one of its own, under `/exit` or under whatever name it prefers.
 * A Host Application that mounts nothing leaves `Ctrl+C` as the only way out.
 *
 * It is also the one command a turn under way does not hold back: leaving asks
 * its question earlier than the answer on its way can spoil, so the
 * Conversation TUI lets this one through while the Agent is working.
 */
final readonly class Leave implements SlashCommand
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

    public function run(Controls $controls, string $arguments): void
    {
        $controls->stop();
    }
}
