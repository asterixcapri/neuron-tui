<?php

declare(strict_types=1);

namespace NeuronTui\Command;

use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\ExitCommand;
use NeuronInteraction\Command\HelpCommand;

/** The terminal's policy for Commands submitted during an active turn. @internal */
final class CommandAvailability
{
    public static function whileWorking(CommandInterface $command): bool
    {
        return $command instanceof HelpCommand || $command instanceof ExitCommand;
    }
}
