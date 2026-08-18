<?php

declare(strict_types=1);

namespace NeuronCli\Conversation;

/**
 * Input a person meant as a Slash command, read as a name and its arguments.
 *
 * The name is what a command answers to, and finding the command that does is
 * someone else's business: this is what was typed, not what it turned out to
 * mean.
 *
 * @internal
 */
final readonly class SlashCommandInput
{
    public function __construct(
        public string $name,
        public string $arguments,
    ) {
    }
}
