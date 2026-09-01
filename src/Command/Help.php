<?php

declare(strict_types=1);

namespace NeuronTui\Command;

use NeuronTui\Conversation\LimitedControls;

/**
 * Lists the mounted commands, so a person can discover what to type here.
 *
 * One of the commands Neuron TUI ships: a Host Application mounts it the way
 * it mounts one of its own, under `/help` or under whatever name it prefers.
 *
 * It is mounted with nothing in hand: the list it shows contains the command
 * showing it, so asking the Host Application to build the list beforehand
 * would ask it for something it does not have yet. The Conversation TUI
 * knows the mounted commands and hands them over as one of the Controls.
 *
 * It runs while the Agent is working too: reading what may be typed here
 * changes nothing, so there is no reason to wait for an answer to be over
 * before asking.
 */
final readonly class Help implements RunsWhileWorking
{
    /**
     * @param string $name the name it answers to, slash included
     */
    public function __construct(private string $name = '/help')
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function describe(): string
    {
        return 'Lists what can be typed here.';
    }

    public function run(LimitedControls $controls, string $arguments): void
    {
        foreach ($controls->commands() as $command) {
            $controls->say($command->name() . ' — ' . $command->describe());
        }
    }
}
