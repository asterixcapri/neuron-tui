<?php

declare(strict_types=1);

namespace NeuronTui\Conversation;

/**
 * A model tool that can produce new conversation input after it has returned.
 */
interface ConversationSource
{
    /**
     * Supplies the current Session's return address before the tool executes.
     */
    public function connect(ConversationPort $conversation): void;
}
