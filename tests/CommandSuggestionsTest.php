<?php

declare(strict_types=1);

namespace NeuronTui\Tests;

use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\ConcurrentCommandInterface;
use NeuronTui\View\CommandSuggestions;
use PHPUnit\Framework\TestCase;

final class CommandSuggestionsTest extends TestCase
{
    public function testFirstDuplicateDeterminesWhetherIdentifierCanBeSuggestedWhileBusy(): void
    {
        $first = $this->createStub(CommandInterface::class);
        $first->method('name')->willReturn('/same');
        $first->method('describe')->willReturn('First');
        $later = $this->createStub(ConcurrentCommandInterface::class);
        $later->method('name')->willReturn('/same');
        $later->method('describe')->willReturn('Later');
        $parallel = $this->createStub(ConcurrentCommandInterface::class);
        $parallel->method('name')->willReturn('/parallel');
        $parallel->method('describe')->willReturn('Concurrent');
        $suggestions = new CommandSuggestions([$first, $later, $parallel]);
        $suggestions->draftChanged('/same');
        self::assertSame('/same', $suggestions->selectedCommandName());
        $suggestions->working();
        self::assertNull($suggestions->selectedCommandName());
        $suggestions->draftChanged('/parallel');
        self::assertSame('/parallel', $suggestions->selectedCommandName());
        $suggestions->ready();
        $suggestions->draftChanged('/same');
        self::assertSame('/same', $suggestions->selectedCommandName());
    }
}
