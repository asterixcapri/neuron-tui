<?php

declare(strict_types=1);

namespace NeuronCli\Conversation;

/**
 * Input meant for the Agent rather than for the Conversation TUI.
 *
 * @internal
 */
final readonly class MessageForAgent
{
    public function __construct(public string $contents)
    {
    }
}
