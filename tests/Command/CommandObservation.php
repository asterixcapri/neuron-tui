<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Command;

use NeuronAI\Agent\Agent;
use NeuronInteraction\Command\CommandAdapterInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\ConcurrentCommandInterface;
use NeuronInteraction\Session\Session;

/** Observe the live conversation only through mounted Commands. */
final class CommandObservation
{
    /** @var CommandAdapterInterface<mixed> */
    private CommandAdapterInterface $adapter;

    public function wrap(Commands $commands): Commands
    {
        $observed = new Commands();
        foreach ($commands->all() as $command) {
            $observed->addCommand($command instanceof ConcurrentCommandInterface
                ? new class ($command, $this) extends ObservedCommand implements ConcurrentCommandInterface {}
                : new ObservedCommand($command, $this));
        }
        return $observed;
    }

    /** @param CommandAdapterInterface<mixed> $adapter */
    public function record(CommandAdapterInterface $adapter): void
    {
        $this->adapter = $adapter;
    }

    public function agent(): Agent
    {
        return $this->adapter->agent();
    }

    public function session(): Session
    {
        return $this->adapter->session();
    }
}
