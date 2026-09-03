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
}
