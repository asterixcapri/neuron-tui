<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Command;

use NeuronAI\Agent\Agent;
use NeuronInteraction\Command\CommandContext;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\ExitCommand;
use NeuronInteraction\Command\HelpCommand;
use NeuronInteraction\Session\Session;

/** Observe the live conversation only through mounted Commands. */
final class CommandObservation
{
    private CommandContext $context;

    public function wrap(Commands $commands): Commands
    {
        $observed = [];
        foreach ($commands->all() as $command) {
            $observed[] = $command instanceof HelpCommand || $command instanceof ExitCommand
                ? $command
                : new ObservedCommand($command, $this);
        }
        return new Commands(...$observed);
    }

    public function record(CommandContext $context): void
    {
        $this->context = $context;
    }

    public function agent(): Agent
    {
        return $this->context->agent();
    }

    public function session(): Session
    {
        return $this->context->session();
    }
}
