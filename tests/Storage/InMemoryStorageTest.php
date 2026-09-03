<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Storage;

use NeuronTui\Storage\InMemoryStorage;
use PHPUnit\Framework\TestCase;

final class InMemoryStorageTest extends TestCase
{
    public function testAMissingValueIsNull(): void
    {
        self::assertNull((new InMemoryStorage())->read('history', 'current'));
    }

    public function testValuesAreIsolatedByNamespaceAndKey(): void
    {
        $storage = new InMemoryStorage();

        $storage->write('sessions', 'first', 'one');
        $storage->write('sessions', 'second', 'two');
        $storage->write('input-history', 'first', 'three');

        self::assertSame('one', $storage->read('sessions', 'first'));
        self::assertSame('two', $storage->read('sessions', 'second'));
        self::assertSame('three', $storage->read('input-history', 'first'));
    }

    public function testWritingAnExistingValueReplacesIt(): void
    {
        $storage = new InMemoryStorage();
        $storage->write('sessions', 'known', 'before');

        $storage->write('sessions', 'known', 'after');

        self::assertSame('after', $storage->read('sessions', 'known'));
    }
}
