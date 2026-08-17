<?php

declare(strict_types=1);

namespace NeuronCli\Tests\Session;

use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronCli\Session\SessionStore;

/**
 * A Session store that keeps its conversations in memory.
 *
 * It stands where a Host Application's own adapter stands, so the feature
 * tests exercise the seam without touching the filesystem. It is not shipped:
 * until a Host Application asks for one, an in-memory store is a testing tool.
 */
final class InMemorySessionStore implements SessionStore
{
    /** @var array<string, ChatHistoryInterface> */
    private array $sessions = [];

    private int $minted = 0;

    public function open(?string $key = null): ChatHistoryInterface
    {
        $key ??= 'session-' . ++$this->minted;

        return $this->sessions[$key] ??= new InMemoryChatHistory();
    }

    /**
     * The Sessions opened so far, in the order they were minted.
     *
     * @return list<ChatHistoryInterface>
     */
    public function sessions(): array
    {
        return array_values($this->sessions);
    }
}
