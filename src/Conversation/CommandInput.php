<?php

declare(strict_types=1);

namespace NeuronTui\Conversation;

use NeuronInteraction\Command\CommandArguments;

/**
 * Input a person meant as a Command, read as a name and its arguments.
 *
 * The name is what a command answers to, and finding the command that does is
 * someone else's business: this is what was typed, not what it turned out to
 * mean.
 *
 * @internal
 */
final readonly class CommandInput
{
    public function __construct(
        public string $name,
        public CommandArguments $arguments,
    ) {
    }
}
