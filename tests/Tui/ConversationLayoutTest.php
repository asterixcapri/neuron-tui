<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Tui;

use Generator;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\HelpCommand;
use NeuronInteraction\Command\LeaveCommand;
use NeuronTui\Tui;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Component\Tui\Terminal\ScreenBuffer;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class ConversationLayoutTest extends TestCase
{
    public function testWorkingCannotMoveTheControlsOnAFullScreen(): void
    {
        $provider = new class(new AssistantMessage('The final answer.')) extends FakeAIProvider {
            protected function streamChunks(Message $response): Generator
            {
                \Amp\delay(0.2);
                yield new TextChunk('stable-layout', 'The final answer.');

                return $response;
            }
        };
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $agent->setChatHistory(ConversationTestHelper::longHistory());
        $terminal = new VirtualTerminal(columns: 80, rows: 24);
        $screen = new ScreenBuffer(80, 24);
        $before = null;
        $working = null;
        $ready = null;

        EventLoop::delay(
            0.04,
            static fn () => $terminal->simulateResize(80, 24),
        );
        EventLoop::delay(
            0.08,
            static function () use (&$before, $screen, $terminal): void {
                $before = ConversationTestHelper::visibleRows($screen, $terminal);
                $terminal->simulateInput("Another question\r");
            },
        );
        EventLoop::delay(
            0.14,
            static function () use (&$working, $screen, $terminal): void {
                $working = ConversationTestHelper::visibleRows($screen, $terminal);
            },
        );
        EventLoop::delay(
            0.36,
            static function () use (&$ready, $screen, $terminal): void {
                $ready = ConversationTestHelper::visibleRows($screen, $terminal);
                $terminal->simulateInput("\x03");
            },
        );

        (new Tui($agent, terminal: $terminal))->run();

        self::assertIsArray($before);
        self::assertIsArray($working);
        self::assertIsArray($ready);
        self::assertControlsAtBottom($before, 24, 'ready');
        self::assertControlsAtBottom($working, 24, 'Enter queues');
        self::assertControlsAtBottom($ready, 24, 'ready');
        self::assertTrue(ConversationTestHelper::contains($working, 'Working'));
        self::assertFalse(ConversationTestHelper::contains($ready, 'Working'));
        self::assertTrue(ConversationTestHelper::contains($ready, 'The final answer.'));
    }

    public function testSuggestionsAndResizeOnlyChangeTheConversationViewport(): void
    {
        $agent = new Agent();
        $agent->setChatHistory(ConversationTestHelper::longHistory());
        $terminal = new VirtualTerminal(columns: 80, rows: 24);
        $screen = new ScreenBuffer(80, 24);
        $open = null;
        $closed = null;
        $resized = null;

        EventLoop::delay(
            0.04,
            static function () use ($screen, $terminal): void {
                ConversationTestHelper::visibleRows($screen, $terminal);
                $terminal->simulateInput('/');
            },
        );
        EventLoop::delay(
            0.1,
            static function () use (&$open, $screen, $terminal): void {
                $open = ConversationTestHelper::visibleRows($screen, $terminal);
                $terminal->simulateInput("\x1b");
            },
        );
        EventLoop::delay(
            0.16,
            static function () use (&$closed, $screen, $terminal): void {
                $closed = ConversationTestHelper::visibleRows($screen, $terminal);
                $terminal->simulateResize(80, 18);
            },
        );
        EventLoop::delay(
            0.22,
            static function () use (&$resized, $terminal): void {
                $resizedScreen = new ScreenBuffer(80, 18);
                $resized = ConversationTestHelper::visibleRows($resizedScreen, $terminal);
                $terminal->simulateInput("\x03");
            },
        );

        (new Tui(
            $agent,
            terminal: $terminal,
            commands: new Commands([new HelpCommand(), new LeaveCommand()]),
        ))->run();

        self::assertIsArray($open);
        self::assertIsArray($closed);
        self::assertIsArray($resized);
        self::assertControlsAtBottom($open, 24, 'suggesting');
        self::assertControlsAtBottom($closed, 24, 'ready');
        self::assertControlsAtBottom($resized, 18, 'ready');
        self::assertTrue(ConversationTestHelper::contains($open, 'Lists what can be typed here.'));
        self::assertFalse(ConversationTestHelper::contains($closed, 'Lists what can be typed here.'));
    }

    public function testQueuedMessagesCannotDisplaceControlsOnASmallScreen(): void
    {
        $provider = new class(new AssistantMessage('Eventually.')) extends FakeAIProvider {
            protected function streamChunks(Message $response): Generator
            {
                \Amp\delay(0.3);
                yield new TextChunk('queued-layout', 'Eventually.');

                return $response;
            }
        };
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $agent->setChatHistory(ConversationTestHelper::longHistory());
        $terminal = new VirtualTerminal(columns: 80, rows: 12);
        $screen = new ScreenBuffer(80, 12);
        $queued = null;

        EventLoop::delay(
            0.03,
            static fn () => $terminal->simulateInput("First queued turn\r"),
        );
        EventLoop::delay(
            0.08,
            static function () use ($terminal): void {
                foreach (range(2, 8) as $number) {
                    $terminal->simulateInput("Queued message {$number}\r");
                }
            },
        );
        EventLoop::delay(
            0.16,
            static function () use (&$queued, $screen, $terminal): void {
                $queued = ConversationTestHelper::visibleRows($screen, $terminal);
                $terminal->simulateInput("\x03");
            },
        );

        (new Tui($agent, terminal: $terminal))->run();

        self::assertIsArray($queued);
        self::assertControlsAtBottom($queued, 12, 'Enter queues');
        self::assertTrue(ConversationTestHelper::contains($queued, 'Queued message 8'));
    }

    public function testShortHistoryStaysAtTheTopAndPagingStillReachesOlderContent(): void
    {
        $shortAgent = new Agent();
        $shortHistory = new InMemoryChatHistory();
        $shortHistory->addMessage(new UserMessage('A short conversation.'));
        $shortAgent->setChatHistory($shortHistory);
        $shortTerminal = new VirtualTerminal(columns: 80, rows: 24);
        $shortScreen = new ScreenBuffer(80, 24);
        $short = null;

        EventLoop::delay(
            0.05,
            static function () use (&$short, $shortScreen, $shortTerminal): void {
                $short = ConversationTestHelper::visibleRows($shortScreen, $shortTerminal);
                $shortTerminal->simulateInput("\x03");
            },
        );
        (new Tui($shortAgent, terminal: $shortTerminal))->run();

        self::assertIsArray($short);
        self::assertStringContainsString('Neuron AI', $short[0]);
        self::assertStringContainsString('A short conversation.', $short[5]);
        self::assertSame('', trim($short[18]));
        self::assertControlsAtBottom($short, 24, 'ready');

        $longAgent = new Agent();
        $longAgent->setChatHistory(ConversationTestHelper::longHistory());
        $longTerminal = new VirtualTerminal(columns: 80, rows: 24);
        $longScreen = new ScreenBuffer(80, 24);
        $latest = null;
        $older = null;
        $latestAgain = null;

        EventLoop::delay(
            0.04,
            static function () use (&$latest, $longScreen, $longTerminal): void {
                $latest = ConversationTestHelper::visibleRows($longScreen, $longTerminal);
                $longTerminal->simulateInput("\x1b[5~");
            },
        );
        EventLoop::delay(
            0.1,
            static function () use (&$older, $longScreen, $longTerminal): void {
                $older = ConversationTestHelper::visibleRows($longScreen, $longTerminal);
                $longTerminal->simulateInput("\x1b[6~");
            },
        );
        EventLoop::delay(
            0.16,
            static function () use (&$latestAgain, $longScreen, $longTerminal): void {
                $latestAgain = ConversationTestHelper::visibleRows($longScreen, $longTerminal);
                $longTerminal->simulateInput("\x03");
            },
        );
        (new Tui($longAgent, terminal: $longTerminal))->run();

        self::assertIsArray($latest);
        self::assertIsArray($older);
        self::assertIsArray($latestAgain);
        self::assertTrue(ConversationTestHelper::contains($latest, 'Answer 20'));
        self::assertFalse(ConversationTestHelper::contains($older, 'Answer 20'));
        self::assertTrue(ConversationTestHelper::contains($older, 'Answer 18'));
        self::assertTrue(ConversationTestHelper::contains($latestAgain, 'Answer 20'));
        self::assertControlsAtBottom($older, 24, 'ready');
    }

    /**
     * @param list<string> $rows
     */
    private static function assertControlsAtBottom(
        array $rows,
        int $height,
        string $status,
    ): void {
        self::assertStringStartsWith('❯', trim($rows[$height - 4]));
        self::assertStringContainsString($status, $rows[$height - 1]);
    }

}
