<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Command;

use NeuronInteraction\Command\CommandAdapterInterface;
use NeuronInteraction\Command\CommandInterface;

class ObservedCommand implements CommandInterface
{
    public function __construct(
        private readonly CommandInterface $command,
        private readonly CommandObservation $observation,
    ) {}

    public function name(): string
    {
        return $this->command->name();
    }

    public function describe(): string
    {
        return $this->command->describe();
    }

    /** @param CommandAdapterInterface<mixed> $adapter */
    public function run(CommandAdapterInterface $adapter, string $value): void
    {
        $this->observation->record($adapter);
        $this->command->run($adapter, $value);
    }
}
