<?php

declare(strict_types=1);

namespace NeuronTui\Command;

use NeuronTui\Conversation\Controls;
use Throwable;

/** Mounted Commands in order, with the first matching identifier winning. */
final readonly class Commands
{
    /** @param list<CommandInterface> $commands */
    public function __construct(private array $commands = [])
    {
    }

    /** @return list<CommandInterface> */
    public function all(): array
    {
        return $this->commands;
    }

    public function named(string $identifier): ?CommandInterface
    {
        foreach ($this->commands as $command) {
            if ($command->name() === $identifier) {
                return $command;
            }
        }

        return null;
    }

    public function run(
        string $identifier,
        CommandArguments $arguments,
        Controls $controls,
    ): CommandExecution {
        $command = $this->named($identifier);

        if ($command === null) {
            return CommandExecution::unknown($identifier);
        }

        try {
            $command->run($controls, $arguments);

            return CommandExecution::completed($identifier);
        } catch (Throwable $exception) {
            return CommandExecution::failed($identifier, $exception);
        }
    }
}
