<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Support;

use Closure;
use NeuronInteraction\Command\CommandAdapterInterface;
use NeuronInteraction\Command\CommandArguments;
use NeuronInteraction\Command\CommandInterface;

/** Observes the active Agent after a real command without changing its behavior. */
final readonly class ObservedCommand implements CommandInterface
{
    /** @param Closure(CommandAdapterInterface<mixed>): void $after */
    public function __construct(private CommandInterface $command, private Closure $after) {}

    public function name(): string
    {
        return $this->command->name();
    }

    public function describe(): string
    {
        return $this->command->describe();
    }

    /** @param CommandAdapterInterface<mixed> $adapter */
    public function run(CommandAdapterInterface $adapter, CommandArguments $arguments): void
    {
        $this->command->run($adapter, $arguments);
        ($this->after)($adapter);
    }
}
