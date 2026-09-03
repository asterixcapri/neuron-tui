<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Storage;

use InvalidArgumentException;
use NeuronTui\Storage\InMemoryStorage;
use PHPUnit\Framework\TestCase;

final class InMemoryStorageTest extends TestCase
{
    public function testCreateReturnsANewOpaqueKeyAndStoredDocument(): void
    {
        $storage = new InMemoryStorage();
        $first = $storage->create('sessions', ['value' => 'one']);
        $second = $storage->create('sessions', ['value' => 'two']);

        self::assertNotSame($first->key, $second->key);
        self::assertSame(['value' => 'one'], $first->data);
        self::assertSame(
            $first->data,
            $storage->read('sessions', $first->key)?->data,
        );
    }

    public function testAMissingDocumentIsNull(): void
    {
        self::assertNull((new InMemoryStorage())->read('history', 'current'));
    }

    public function testDocumentsAreIsolatedByNamespaceAndKey(): void
    {
        $storage = new InMemoryStorage();

        $storage->write('sessions', 'first', ['value' => 'one']);
        $storage->write('sessions', 'second', ['value' => 'two']);
        $storage->write('input-history', 'first', ['value' => 'three']);

        self::assertSame(
            ['value' => 'one'],
            $storage->read('sessions', 'first')?->data,
        );
        self::assertSame(
            ['value' => 'two'],
            $storage->read('sessions', 'second')?->data,
        );
        self::assertSame(
            ['value' => 'three'],
            $storage->read('input-history', 'first')?->data,
        );
    }

    public function testWritingAnExistingValueReplacesIt(): void
    {
        $storage = new InMemoryStorage();
        $storage->write('sessions', 'known', ['value' => 'before']);

        $written = $storage->write(
            'sessions',
            'known',
            ['value' => 'after'],
        );

        self::assertSame(
            ['value' => 'after'],
            $written->data,
        );
    }

    public function testEntriesExposeLogicalKeysAndJsonDocumentBehaviour(): void
    {
        $storage = new InMemoryStorage();
        $storage->write('sessions', 'first', ['value' => 'one']);
        $storage->write('sessions', 'second', ['value' => 'two']);

        $entries = iterator_to_array($storage->entries('sessions'));

        self::assertSame(['first', 'second'], array_column($entries, 'key'));
        self::assertSame(
            strlen('{"value":"two"}'),
            $entries[1]->size(),
        );
    }

    public function testMetadataRoundTripsAndDeleteIsIdempotent(): void
    {
        $storage = new InMemoryStorage();
        $document = $storage->create(
            'sessions',
            ['value'],
            ['lastUsedAt' => '2026-09-03T12:00:00+00:00'],
        );

        self::assertSame(
            ['lastUsedAt' => '2026-09-03T12:00:00+00:00'],
            $document->metadata,
        );

        $storage->delete('sessions', $document->key);
        $storage->delete('sessions', $document->key);

        self::assertNull($storage->read('sessions', $document->key));
    }

    public function testMetadataNamesMustBePortable(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new InMemoryStorage())->write(
            'sessions',
            'known',
            ['value'],
            ['LastUsedAt' => '2026-09-03T12:00:00+00:00'],
        );
    }
}
