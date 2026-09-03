<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Tui;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronTui\Storage\InMemoryStorage;
use NeuronTui\Tui;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class InputHistoryTest extends TestCase
{
    public function testUpRecallsTheNewestSubmittedMessageAtItsEnd(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('An answer.'));
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $storage = new InMemoryStorage();
        $terminal = new VirtualTerminal(rows: 24);

        EventLoop::queue(
            static fn () => $terminal->simulateInput("  Remember me  \r"),
        );
        EventLoop::delay(
            0.12,
            static function () use ($terminal): void {
                $terminal->simulateInput("\x1b[A");
                $terminal->simulateInput('again');
                $terminal->simulateInput("\r");
            },
        );
        EventLoop::delay(
            0.35,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new Tui($agent, $terminal))->setStorage($storage)->run();

        self::assertSame(
            ['  Remember me  ', '  Remember me  again'],
            $storage->read('input-history', 'entries')?->data,
        );
    }

    public function testUpWithNoStoredInputsLeavesTheComposerEmpty(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('An answer.'));
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $storage = new InMemoryStorage();
        $terminal = new VirtualTerminal(rows: 24);

        EventLoop::queue(
            static function () use ($terminal): void {
                $terminal->simulateInput("\x1b[A");
                $terminal->simulateInput("Fresh message\r");
            },
        );
        EventLoop::delay(
            0.2,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new Tui($agent, $terminal))->setStorage($storage)->run();

        self::assertCount(1, $provider->getRecorded());
        self::assertSame(
            'Fresh message',
            $provider->getRecorded()[0]->messages[0]->getContent(),
        );
    }

    public function testHistoryMovesBothWaysAndPastNewestRestoresEmpty(): void
    {
        [$provider, $storage, $terminal, $agent] = $this->tuiWithHistory([
            'oldest',
            'middle',
            'newest',
        ]);

        EventLoop::queue(static function () use ($terminal): void {
            $terminal->simulateInput(str_repeat("\x1b[A", 4));
            $terminal->simulateInput(str_repeat("\x1b[B", 3));
            $terminal->simulateInput("After history\r");
        });
        EventLoop::delay(
            0.2,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new Tui($agent, $terminal))->setStorage($storage)->run();

        self::assertSame(
            'After history',
            $provider->getRecorded()[0]->messages[0]->getContent(),
        );
    }

    public function testEditingARecallMakesItAnOrdinaryDraft(): void
    {
        [$provider, $storage, $terminal, $agent] = $this->tuiWithHistory([
            'older',
            'newest',
        ]);

        EventLoop::queue(static function () use ($terminal): void {
            $terminal->simulateInput("\x1b[A");
            $terminal->simulateInput('-edited');
            $terminal->simulateInput("\x1b[A");
            $terminal->simulateInput('prefix-');
            $terminal->simulateInput("\r");
        });
        EventLoop::delay(
            0.2,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new Tui($agent, $terminal))->setStorage($storage)->run();

        self::assertSame(
            'prefix-newest-edited',
            $provider->getRecorded()[0]->messages[0]->getContent(),
        );
        self::assertSame(
            ['older', 'newest', 'prefix-newest-edited'],
            $storage->read('input-history', 'entries')?->data,
        );
    }

    public function testMultilineRecallPlacesTheCursorAtTheEnd(): void
    {
        [$provider, $storage, $terminal, $agent] = $this->tuiWithHistory([
            "first line\nsecond line",
        ]);

        EventLoop::queue(static function () use ($terminal): void {
            $terminal->simulateInput("\x1b[A");
            $terminal->simulateInput("!\r");
        });
        EventLoop::delay(
            0.2,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new Tui($agent, $terminal))->setStorage($storage)->run();

        self::assertSame(
            "first line\nsecond line!",
            $provider->getRecorded()[0]->messages[0]->getContent(),
        );
    }

    public function testArrowsKeepTheirEditorBehaviourInAMultilineDraft(): void
    {
        [$provider, $storage, $terminal, $agent] = $this->tuiWithHistory([
            'stored input',
        ]);

        EventLoop::queue(static function () use ($terminal): void {
            $terminal->simulateInput('first');
            $terminal->simulateInput("\x1b[13;2u");
            $terminal->simulateInput('second');
            $terminal->simulateInput("\x1b[A!");
            $terminal->simulateInput("\x1b[B?\r");
        });
        EventLoop::delay(
            0.2,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new Tui($agent, $terminal))->setStorage($storage)->run();

        self::assertSame(
            "first!\nsecond?",
            $provider->getRecorded()[0]->messages[0]->getContent(),
        );
    }

    public function testArrowsKeepTheirEditorBehaviourInAVisuallyWrappedDraft(): void
    {
        [$provider, $storage, $terminal, $agent] = $this->tuiWithHistory(
            ['stored input'],
            columns: 30,
        );
        $draft = str_repeat('wrapped ', 8);

        EventLoop::queue(static function () use ($terminal, $draft): void {
            $terminal->simulateInput($draft);
            $terminal->simulateInput("\x1b[A[");
            $terminal->simulateInput("\x1b[B]\r");
        });
        EventLoop::delay(
            0.2,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new Tui($agent, $terminal))->setStorage($storage)->run();

        self::assertSame(
            '[' . $draft . ']',
            $provider->getRecorded()[0]->messages[0]->getContent(),
        );
    }

    /**
     * @param list<string> $entries
     *
     * @return array{FakeAIProvider, InMemoryStorage, VirtualTerminal, Agent}
     */
    private function tuiWithHistory(
        array $entries,
        int $columns = 80,
    ): array {
        $provider = new FakeAIProvider(new AssistantMessage('An answer.'));
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $storage = new InMemoryStorage();
        $storage->write('input-history', 'entries', $entries);

        return [
            $provider,
            $storage,
            new VirtualTerminal(columns: $columns, rows: 24),
            $agent,
        ];
    }
}
