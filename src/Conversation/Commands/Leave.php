<?php

declare(strict_types=1);

namespace NeuronCli\Conversation\Commands;

use NeuronCli\Conversation\LimitedControls;
use NeuronCli\Conversation\RunsWhileWorking;

/**
 * Leaves the terminal.
 *
 * One of the commands Neuron CLI ships: a Host Application mounts it the way
 * it mounts one of its own, under `/exit` or under whatever name it prefers.
 * A Host Application that mounts nothing leaves `Ctrl+C` as the only way out.
 *
 * It runs while the Agent is working too: leaving asks its question earlier
 * than the answer on its way can spoil, so it says as much in its type and
 * takes the fewer Controls that come with saying it.
 */
final readonly class Leave implements RunsWhileWorking
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

    public function run(LimitedControls $controls, string $arguments): void
    {
        $controls->stop();
    }
}
