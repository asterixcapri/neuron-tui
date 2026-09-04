<?php

declare(strict_types=1);

namespace NeuronTui\Subagent;

use NeuronAI\Chat\History\InMemoryChatHistory;

/** @internal */
final class SerializedChatHistory extends InMemoryChatHistory
{
    /**
     * @param list<array<string, mixed>> $messages
     */
    public function __construct(array $messages)
    {
        parent::__construct();
        $this->history = $this->deserializeMessages($messages);
    }
}
