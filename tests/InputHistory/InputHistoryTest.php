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
    public function testInputIsPersistedOutsideSessions(): void
    {
        $storage = new InMemoryStorage();
        $inputHistory = new InputHistory($storage);

        self::assertNull($inputHistory->older());

        $inputHistory->record('  exact composer text  ');

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
            (new InputHistory($storage))->older(),
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
        $inputHistory = new InputHistory($storage);

        self::assertSame(1, $storage->reads);
        self::assertSame('newest', $inputHistory->older());
        self::assertSame('middle', $inputHistory->older());
        self::assertSame('oldest', $inputHistory->older());
        self::assertSame('oldest', $inputHistory->older());
        self::assertSame('middle', $inputHistory->newer());
        self::assertSame('newest', $inputHistory->newer());
        self::assertSame('', $inputHistory->newer());
        self::assertNull($inputHistory->newer());
        self::assertFalse($inputHistory->isNavigating());
        self::assertSame(1, $storage->reads);
        self::assertSame(0, $storage->writes);
    }

    public function testOnlyConsecutiveExactDuplicateSubmissionsCollapse(): void
    {
        $stored = new InMemoryStorage();
        $storage = new CountingStorage($stored);
        $inputHistory = new InputHistory($storage);

        $inputHistory->record('same');
        $inputHistory->record('same');
        $inputHistory->record(" \n\t ");
        $inputHistory->record('different');
        $inputHistory->record('same');

        self::assertSame(
            ['same', 'different', 'same'],
            $stored->read('input-history', 'entries')?->data,
        );
        self::assertSame(3, $storage->writes);
    }

    public function testEditingOrSubmittingLeavesNavigation(): void
    {
        $storage = new InMemoryStorage();
        $inputHistory = new InputHistory($storage);
        $inputHistory->record('remembered');

        self::assertSame('remembered', $inputHistory->older());
        self::assertTrue($inputHistory->isNavigating());

        $inputHistory->leave();

        self::assertFalse($inputHistory->isNavigating());

        self::assertSame('remembered', $inputHistory->older());
        $inputHistory->record('remembered');

        self::assertFalse($inputHistory->isNavigating());
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
