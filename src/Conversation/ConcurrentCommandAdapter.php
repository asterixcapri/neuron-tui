<?php

declare(strict_types=1);

namespace NeuronTui\Conversation;

use NeuronInteraction\Command\CommandArguments;
use NeuronInteraction\Command\CommandControlsInterface;
use NeuronInteraction\Command\CommandInterface;
use NeuronTui\Command\ConcurrentCommandInterface;

/** @internal Adapts terminal concurrency to ordinary Command dispatch. */
final readonly class ConcurrentCommandAdapter implements CommandInterface
{
    public function __construct(
        private ConcurrentCommandInterface $command,
        private ConcurrentControls $controls,
    ) {
    }

    public function name(): string
    {
        return $this->command->name();
    }

    public function describe(): string
    {
        return $this->command->describe();
    }

    public function run(CommandControlsInterface $controls, CommandArguments $arguments): void
    {
        $this->command->run($this->controls, $arguments);
    }
}
