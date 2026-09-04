<?php

declare(strict_types=1);

namespace NeuronTui\Command;

use NeuronInteraction\Command\CommandArguments;
use NeuronTui\Conversation\ConcurrentControls;

/**
 * Lists the mounted commands, so a person can discover what to type here.
 *
 * One of the commands Neuron TUI ships: a Host Application mounts it the way
 * it mounts one of its own, under `help` or under whatever name it prefers.
 *
 * It is mounted with nothing in hand: the list it shows contains the command
 * showing it, so asking the Host Application to build the list beforehand
 * would ask it for something it does not have yet. The Conversation TUI
 * knows the mounted commands and hands them over as one of the Controls.
 *
 * It is Concurrent: reading what may be typed here changes nothing, so there
 * is no reason to wait for a Turn to finish before asking.
 */
final readonly class HelpCommand implements ConcurrentCommandInterface
{
    /**
     * @param string $name the name it answers to, slash omitted
     */
    public function __construct(private string $name = 'help')
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

    public function run(ConcurrentControls $controls, CommandArguments $arguments): void
    {
        foreach ($controls->commands()->all() as $command) {
            $controls->say('/' . $command->name() . ' — ' . $command->describe());
        }
    }
}
