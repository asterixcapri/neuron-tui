<?php

declare(strict_types=1);

namespace NeuronTui\Tests\History;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\History\InMemoryMessageStore;

use function bin2hex;
use function random_bytes;

/** A seeded conversation sharing its store with a test agent. */
class SeededHistory extends ChatHistory
{
    public function __construct()
    {
        parent::__construct(new InMemoryMessageStore(), bin2hex(random_bytes(12)));
    }

    public function bindTo(Agent $agent): Agent
    {
        if ($agent->getThreadId() !== null && $agent->getThreadId() !== $this->threadId) {
            $agent = $agent->for($this->threadId);
        }
        return $agent->setThreadId($this->threadId)->setMessageStore($this->store);
    }
}
