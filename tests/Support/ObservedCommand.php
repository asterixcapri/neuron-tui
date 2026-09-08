<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Support;

use Closure;
use NeuronInteraction\Command\CommandControlsAdapterInterface;
use NeuronInteraction\Command\CommandArguments;
use NeuronInteraction\Command\CommandInterface;

/** Observes the active Agent after a real command without changing its behavior. */
final readonly class ObservedCommand implements CommandInterface
{
    /** @param Closure(CommandControlsAdapterInterface<mixed>): void $after */
    public function __construct(private CommandInterface $command, private Closure $after) {}

    public function name(): string
    {
        return $this->command->name();
    }

    public function describe(): string
    {
        return $this->command->describe();
    }

    /** @param CommandControlsAdapterInterface<mixed> $controls */
    public function run(CommandControlsAdapterInterface $controls, CommandArguments $arguments): void
    {
        $this->command->run($controls, $arguments);
        ($this->after)($controls);
    }
}
