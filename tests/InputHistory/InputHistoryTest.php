<?php

declare(strict_types=1);

namespace NeuronTui\Tests\InputHistory;

use NeuronTui\InputHistory\InputHistory;
use NeuronTui\Storage\InMemoryStorage;
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
}
