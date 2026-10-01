<?php

declare(strict_types=1);

namespace NeuronTui\Conversation;

use NeuronAI\Chat\Messages\UserMessage;

/** Original client input waiting to be submitted to the core. @internal */
final readonly class PendingMessage
{
    public function __construct(public UserMessage $message, public bool $userInput) {}
}
