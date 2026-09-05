<?php

declare(strict_types=1);

namespace NeuronTui\Conversation;

use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\HelpCommand;
use NeuronInteraction\Command\LeaveCommand;

/** @internal The TUI permits only Help and Leave while a Turn is occupied. */
final class ConcurrentCommands
{
    public static function allows(CommandInterface $command): bool
    {
        return $command instanceof HelpCommand || $command instanceof LeaveCommand;
    }
}
