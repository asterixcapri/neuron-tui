<?php

declare(strict_types=1);

namespace NeuronTui\Session;

use DateTimeImmutable;
use InvalidArgumentException;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronTui\History\HistoryProjection;
use NeuronTui\Storage\StorageInterface;
use NeuronTui\Storage\StoredDocument;
use UnexpectedValueException;

/**
 * Owns the lifecycle of conversations persisted through shared storage.
 */
final readonly class Sessions
{
    private const string NAMESPACE = 'sessions';

    public function __construct(private StorageInterface $storage) {}

    /**
     * Starts a distinct Session and returns its empty History.
     */
    public function start(): ChatHistoryInterface
    {
        $document = $this->storage->create(self::NAMESPACE, []);

        return $this->history($document);
    }

    /**
     * Returns non-empty Sessions, most recently used first.
     *
     * @return list<Session>
     */
    public function list(): array
    {
        $sessions = [];

        foreach ($this->storage->entries(self::NAMESPACE) as $document) {
            $title = (new HistoryProjection(
                $this->history($document)->getMessages(),
            ))->openingWords();

            if ($title === null) {
                continue;
            }

            $sessions[] = new Session(
                $document->key,
                $this->lastUsedAt($document),
                $title,
                $document->size(),
            );
        }

        usort(
            $sessions,
            static fn (Session $one, Session $other): int =>
                ($other->lastUsedAt <=> $one->lastUsedAt)
                    ?: ($one->key <=> $other->key),
        );

        return $sessions;
    }

    /**
     * Resumes an existing Session. Only start() creates a key.
     */
    public function resume(string $key): ChatHistoryInterface
    {
        $document = $this->storage->read(self::NAMESPACE, $key);

        if ($document === null) {
            throw new InvalidArgumentException(
                'No Session is named by that key.',
            );
        }

        return $this->history($document);
    }

    private function history(StoredDocument $document): ChatHistoryInterface
    {
        return new StorageChatHistory(
            $this->storage,
            self::NAMESPACE,
            $document->key,
            document: $document,
        );
    }

    private function lastUsedAt(StoredDocument $document): DateTimeImmutable
    {
        $value = $document->metadata['lastUsedAt'] ?? null;
        $lastUsedAt = $value === null
            ? false
            : DateTimeImmutable::createFromFormat(
                '!Y-m-d\TH:i:s.uP',
                $value,
            );

        if ($lastUsedAt === false) {
            throw new UnexpectedValueException(
                'A stored Session must contain a valid last-used time.',
            );
        }

        return $lastUsedAt;
    }
}
