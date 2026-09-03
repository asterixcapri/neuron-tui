<?php

declare(strict_types=1);

namespace NeuronTui\Tests\InputHistory;

use NeuronTui\InputHistory\InputHistory;
use NeuronTui\Storage\InMemoryStorage;
use NeuronTui\Storage\StoredDocument;
use NeuronTui\Storage\StorageInterface;
use PHPUnit\Framework\TestCase;

final class InputHistoryTest extends TestCase
{
    public function testTheNewestInputIsPersistedOutsideSessions(): void
    {
        $storage = new InMemoryStorage();
        $history = new InputHistory($storage);

        self::assertNull($history->newest());

        $history->record('  exact composer text  ');

        self::assertSame('  exact composer text  ', $history->newest());
        self::assertSame(
            ['  exact composer text  '],
            $storage->read('input-history', 'entries')?->data,
        );
        self::assertSame(
            [],
            iterator_to_array($storage->entries('sessions')),
        );
        self::assertSame(
            '  exact composer text  ',
            (new InputHistory($storage))->newest(),
        );
    }

    public function testNavigationMovesThroughTheLoadedEntriesInMemory(): void
    {
        $stored = new InMemoryStorage();
        $stored->write(
            'input-history',
            'entries',
            ['oldest', 'middle', 'newest'],
        );
        $storage = new CountingStorage($stored);
        $history = new InputHistory($storage);

        self::assertSame(1, $storage->reads);
        self::assertSame('newest', $history->older());
        self::assertSame('middle', $history->older());
        self::assertSame('oldest', $history->older());
        self::assertSame('oldest', $history->older());
        self::assertSame('middle', $history->newer());
        self::assertSame('newest', $history->newer());
        self::assertSame('', $history->newer());
        self::assertNull($history->newer());
        self::assertFalse($history->isNavigating());
        self::assertSame(1, $storage->reads);
        self::assertSame(0, $storage->writes);
    }

    public function testOnlyConsecutiveExactDuplicateSubmissionsCollapse(): void
    {
        $stored = new InMemoryStorage();
        $storage = new CountingStorage($stored);
        $history = new InputHistory($storage);

        $history->record('same');
        $history->record('same');
        $history->record(" \n\t ");
        $history->record('different');
        $history->record('same');

        self::assertSame(
            ['same', 'different', 'same'],
            $stored->read('input-history', 'entries')?->data,
        );
        self::assertSame(3, $storage->writes);
    }

    public function testEditingOrSubmittingLeavesNavigation(): void
    {
        $storage = new InMemoryStorage();
        $history = new InputHistory($storage);
        $history->record('remembered');

        self::assertSame('remembered', $history->older());
        self::assertTrue($history->isNavigating());

        $history->leave();

        self::assertFalse($history->isNavigating());

        self::assertSame('remembered', $history->older());
        $history->record('remembered');

        self::assertFalse($history->isNavigating());
        self::assertSame(
            ['remembered'],
            $storage->read('input-history', 'entries')?->data,
        );
    }
}

/** @internal */
final class CountingStorage implements StorageInterface
{
    public int $reads = 0;

    public int $writes = 0;

    public function __construct(private readonly StorageInterface $storage)
    {
    }

    public function create(
        string $namespace,
        array $data,
        array $metadata = [],
    ): StoredDocument {
        return $this->storage->create($namespace, $data, $metadata);
    }

    public function read(string $namespace, string $key): ?StoredDocument
    {
        ++$this->reads;

        return $this->storage->read($namespace, $key);
    }

    public function write(
        string $namespace,
        string $key,
        array $data,
        array $metadata = [],
    ): StoredDocument {
        ++$this->writes;

        return $this->storage->write($namespace, $key, $data, $metadata);
    }

    public function delete(string $namespace, string $key): void
    {
        $this->storage->delete($namespace, $key);
    }

    public function entries(string $namespace): iterable
    {
        return $this->storage->entries($namespace);
    }
}
