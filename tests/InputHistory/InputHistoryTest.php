<?php

declare(strict_types=1);

namespace NeuronTui\Tests\InputHistory;

use NeuronTui\InputHistory\InputHistory;
use NeuronTui\Storage\InMemoryStorage;
use PHPUnit\Framework\TestCase;

final class InputHistoryTest extends TestCase
{
    public function testInputIsPersistedOutsideSessions(): void
    {
        $storage = new InMemoryStorage();
        $inputHistory = new InputHistory($storage);

        self::assertSame([], $inputHistory->entries());

        $inputHistory->record('  exact composer text  ');
        $inputHistory->record('/resume session-key');

        self::assertSame(
            ['  exact composer text  ', '/resume session-key'],
            (new InputHistory($storage))->entries(),
        );
        self::assertSame([], iterator_to_array($storage->entries('sessions')));
    }

    public function testOnlyConsecutiveExactDuplicateSubmissionsCollapse(): void
    {
        $storage = new InMemoryStorage();
        $inputHistory = new InputHistory($storage);

        $inputHistory->record('same');
        $inputHistory->record('same');
        $inputHistory->record(" \n\t ");
        $inputHistory->record('different');
        $inputHistory->record('same');
        $inputHistory->record(' same ');

        self::assertSame(
            ['same', 'different', 'same', ' same '],
            (new InputHistory($storage))->entries(),
        );
    }
}
