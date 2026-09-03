<?php

declare(strict_types=1);

namespace NeuronTui\Session;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronTui\History\HistoryProjection;
use NeuronTui\Storage\FileStorage;
use NeuronTui\Storage\StorageInterface;
use UnexpectedValueException;

use function array_is_list;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function strlen;

use const JSON_THROW_ON_ERROR;

/**
 * Owns the lifecycle of conversations persisted through shared storage.
 */
final class Sessions
{
    private const string NAMESPACE = 'sessions';

    private const string INDEX_KEY = '_index.json';

    private const string FILE_EXTENSION = '.json';

    /**
     * @var array<string, DateTimeImmutable>|null
     */
    private ?array $index = null;

    public function __construct(private readonly StorageInterface $storage) {}

    /**
     * Starts a distinct Session and returns its empty History.
     */
    public function start(): ChatHistoryInterface
    {
        $index = $this->index();

        do {
            $key = bin2hex(random_bytes(8));
        } while (isset($index[$key]));

        $index[$key] = $this->nextMoment($index);
        $this->saveIndex($index);

        return $this->history($key);
    }

    /**
     * Returns non-empty Sessions, most recently used first.
     *
     * @return list<Session>
     */
    public function list(): array
    {
        $sessions = [];

        foreach ($this->index() as $key => $lastUsedAt) {
            $value = $this->storage->read(
                self::NAMESPACE,
                $this->payloadKey($key),
            );

            if ($value === null) {
                continue;
            }

            $title = HistoryProjection::openingWords(
                $this->history($key)->getMessages(),
            );

            if ($title === null) {
                continue;
            }

            $sessions[] = new Session(
                $key,
                $lastUsedAt,
                $title,
                $this->storage instanceof FileStorage ? strlen($value) : null,
            );
        }

        usort(
            $sessions,
            static fn (Session $one, Session $other): int =>
                $other->lastUsedAt <=> $one->lastUsedAt,
        );

        return $sessions;
    }

    /**
     * Resumes an existing Session. Only start() creates a key.
     */
    public function resume(string $key): ChatHistoryInterface
    {
        if (!isset($this->index()[$key])) {
            throw new InvalidArgumentException(
                'No Session is named by that key.',
            );
        }

        return $this->history($key);
    }

    private function history(string $key): ChatHistoryInterface
    {
        return new StorageChatHistory(
            $this->storage,
            self::NAMESPACE,
            $this->payloadKey($key),
            afterWrite: fn () => $this->recordUse($key),
        );
    }

    private function recordUse(string $key): void
    {
        $index = $this->index();

        if (!isset($index[$key])) {
            return;
        }

        $index[$key] = $this->nextMoment($index);
        $this->saveIndex($index);
    }

    /**
     * @return array<string, DateTimeImmutable>
     */
    private function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $stored = $this->storage->read(self::NAMESPACE, self::INDEX_KEY);

        if ($stored === null) {
            return $this->index = [];
        }

        try {
            $entries = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException(
                'The Session index must be valid JSON.',
                previous: $exception,
            );
        }

        if (!is_array($entries) || !array_is_list($entries)) {
            throw new UnexpectedValueException(
                'The Session index must be a JSON array.',
            );
        }

        $index = [];

        foreach ($entries as $entry) {
            if (
                !is_array($entry)
                || !isset($entry['key'], $entry['lastUsedAt'])
                || !is_string($entry['key'])
                || !is_string($entry['lastUsedAt'])
                || preg_match('/^[a-f0-9]{16}$/D', $entry['key']) !== 1
            ) {
                throw new UnexpectedValueException(
                    'Every Session index entry must contain a valid key and time.',
                );
            }

            $lastUsedAt = DateTimeImmutable::createFromFormat(
                '!Y-m-d\\TH:i:s.uP',
                $entry['lastUsedAt'],
            );

            if ($lastUsedAt === false) {
                throw new UnexpectedValueException(
                    'Every Session index entry must contain a valid key and time.',
                );
            }

            $index[$entry['key']] = $lastUsedAt;
        }

        return $this->index = $index;
    }

    /**
     * @param array<string, DateTimeImmutable> $index
     */
    private function saveIndex(array $index): void
    {
        $entries = [];

        foreach ($index as $key => $lastUsedAt) {
            $entries[] = [
                'key' => $key,
                'lastUsedAt' => $lastUsedAt->format('Y-m-d\\TH:i:s.uP'),
            ];
        }

        $this->storage->write(
            self::NAMESPACE,
            self::INDEX_KEY,
            json_encode($entries, JSON_THROW_ON_ERROR),
        );
        $this->index = $index;
    }

    /**
     * Ensures two writes in the same clock tick still have a stable order.
     *
     * @param array<string, DateTimeImmutable> $index
     */
    private function nextMoment(array $index): DateTimeImmutable
    {
        $now = new DateTimeImmutable();

        foreach ($index as $lastUsedAt) {
            if ($now <= $lastUsedAt) {
                $now = $lastUsedAt->modify('+1 microsecond');
            }
        }

        return $now;
    }

    private function payloadKey(string $key): string
    {
        return $key . self::FILE_EXTENSION;
    }
}
