<?php

declare(strict_types=1);

namespace NeuronCli\Conversation;

/**
 * Input claiming to be a Slash command that is none of the three.
 *
 * The reserved namespace is answered locally rather than sent to the Agent.
 *
 * @internal
 */
final readonly class UnknownSlashCommand
{
    public function __construct(public string $name)
    {
    }
}
