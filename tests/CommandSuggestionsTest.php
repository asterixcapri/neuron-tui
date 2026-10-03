<?php

declare(strict_types=1);

namespace NeuronTui\Tests;

use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\HelpCommand;
use NeuronInteraction\Command\LeaveCommand;
use NeuronTui\View\CommandSuggestions;
use PHPUnit\Framework\TestCase;

final class CommandSuggestionsTest extends TestCase
{
    public function testBusySuggestionsFollowTheTerminalsHelpAndExitPolicy(): void
    {
        $ordinary = $this->createStub(CommandInterface::class);
        $ordinary->method('name')->willReturn('/ordinary');
        $ordinary->method('describe')->willReturn('Ordinary');
        $suggestions = new CommandSuggestions([$ordinary, new HelpCommand('/guide'), new LeaveCommand('/quit')]);
        $suggestions->draftChanged('/ordinary');
        self::assertSame('/ordinary', $suggestions->selectedCommandName());
        $suggestions->working();
        self::assertNull($suggestions->selectedCommandName());
        $suggestions->draftChanged('/guide');
        self::assertSame('/guide', $suggestions->selectedCommandName());
        $suggestions->draftChanged('/quit');
        self::assertSame('/quit', $suggestions->selectedCommandName());
        $suggestions->ready();
        $suggestions->draftChanged('/ordinary');
        self::assertSame('/ordinary', $suggestions->selectedCommandName());
    }
}
