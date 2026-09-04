<?php

declare(strict_types=1);

namespace NeuronTui\Conversation;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;

/**
 * Input meant for the Agent rather than for the Conversation TUI.
 *
 * @internal
 */
final readonly class MessageForAgent implements ConversationInput
{
    public function __construct(public string $contents)
    {
    }

    public function message(): Message
    {
        return new UserMessage($this->contents);
    }
}
