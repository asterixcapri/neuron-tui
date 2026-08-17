<?php

declare(strict_types=1);

namespace NeuronCli\Tests\Session;

use DateTimeImmutable;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronCli\History\HistoryProjection;
use NeuronCli\Session\Session;
use NeuronCli\Session\SessionProvider;

/**
 * A Session provider that keeps its conversations in memory.
 *
 * It stands where a Host Application's own adapter stands, so the feature
 * tests exercise the seam without touching the filesystem. It is not shipped:
 * until a Host Application asks for one, an in-memory provider is a testing
 * tool.
 *
 * Being that tool, it accepts a key it never minted and starts a Session
 * under it, which is how a test says what was written before the TUI opened.
 */
final class InMemorySessionProvider implements SessionProvider
{
    /** @var array<string, ChatHistoryInterface> */
    private array $sessions = [];

    private int $minted = 0;

    public function create(): Session
    {
        $key = 'session-' . ++$this->minted;
        $this->sessions[$key] = new InMemoryChatHistory();

        return new Session($key, new DateTimeImmutable(), '');
    }

    /**
     * Nothing in memory records when a conversation was written to, so the
     * order Sessions were opened in stands for how recently they were used:
     * the one opened last comes first, an hour younger than the one before.
     */
    public function list(): array
    {
        $listed = [];
        $openedAt = new DateTimeImmutable('2026-01-01 00:00:00');

        foreach ($this->sessions as $key => $session) {
            $title = HistoryProjection::openingWords(
                $session->getMessages(),
            );

            if ($title === null) {
                continue;
            }

            $listed[] = new Session($key, $openedAt, $title);
            $openedAt = $openedAt->modify('+1 hour');
        }

        return array_reverse($listed);
    }

    public function open(string $key): ChatHistoryInterface
    {
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
