<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Tui;

use Generator;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\Tool;
use NeuronTui\Tui;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Component\Tui\Terminal\ScreenBuffer;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class ConversationReadingPositionTest extends TestCase
{
    public function testLiveTextAndWorkingActivityPreserveReadingUntilTheReaderReturnsToLatest(): void
    {
        $provider = new class(new AssistantMessage("first new line\nsecond new line\nlatest streamed line")) extends FakeAIProvider {
            protected function streamChunks(Message $response): Generator
            {
                \Amp\delay(0.2);
                yield new TextChunk(
                    'reading-position',
                    "first new line\nsecond new line",
                );
                \Amp\delay(0.25);
                yield new TextChunk(
                    'reading-position',
                    "\nlatest streamed line",
                );

                return $response;
            }
        };
        $agent = self::agentWithLongHistory();
        $agent->setAiProvider($provider);
        $terminal = new VirtualTerminal(columns: 80, rows: 24);
        $screen = new ScreenBuffer(80, 24);
        $following = null;
        $beforeGrowth = null;
        $afterWorkingUpdate = null;
        $afterGrowth = null;
        $followingAgain = null;
        $afterLatest = null;

        EventLoop::delay(
            0.03,
            static fn () => $terminal->simulateInput("Continue\r"),
        );
        EventLoop::delay(
            0.08,
            static function () use (&$following, $screen, $terminal): void {
                $following = self::visibleRows($screen, $terminal);
                $terminal->simulateInput("\x1b[5~");
            },
        );
        EventLoop::delay(
            0.12,
            static function () use (&$beforeGrowth, $screen, $terminal): void {
                $beforeGrowth = self::conversationRows($screen, $terminal);
            },
        );
        EventLoop::delay(
            0.18,
            static function () use (&$afterWorkingUpdate, $screen, $terminal): void {
                $afterWorkingUpdate = self::conversationRows($screen, $terminal);
            },
        );
        EventLoop::delay(
            0.3,
            static function () use (&$afterGrowth, $screen, $terminal): void {
                $afterGrowth = self::conversationRows($screen, $terminal);

                foreach (range(1, 10) as $_) {
                    $terminal->simulateInput("\x1b[6~");
                }
            },
        );
        EventLoop::delay(
            0.38,
            static function () use (&$followingAgain, $screen, $terminal): void {
                $followingAgain = self::visibleRows($screen, $terminal);
            },
        );
        EventLoop::delay(
            0.55,
            static function () use (&$afterLatest, $screen, $terminal): void {
                $afterLatest = self::visibleRows($screen, $terminal);
                $terminal->simulateInput("\x03");
            },
        );

        (new Tui($agent, terminal: $terminal))->run();

        self::assertIsArray($following);
        self::assertIsArray($beforeGrowth);
        self::assertIsArray($afterWorkingUpdate);
        self::assertIsArray($afterGrowth);
        self::assertIsArray($followingAgain);
        self::assertIsArray($afterLatest);
        self::assertTrue(self::contains($following, 'Continue'));
        self::assertTrue(self::contains($following, 'Working'));
        self::assertSame($beforeGrowth, $afterWorkingUpdate);
        self::assertSame($beforeGrowth, $afterGrowth);
        self::assertTrue(self::contains($followingAgain, 'second new line'));
        self::assertTrue(self::contains($afterLatest, 'latest streamed line'));
        self::assertFalse(self::contains($afterLatest, 'Working'));
    }

    public function testToolResultsAndResizePreserveTheVisibleConversationWhileReading(): void
    {
        $tool = (new Tool('lookup'))
            ->setCallId('stable-reading-tool')
            ->setInputs(['record' => 'alpha'])
            ->setCallable(static function (): string {
                \Amp\delay(0.2);

                return implode(' ', array_fill(0, 30, 'expanded-result'));
            });
        $provider = new class(
            new ToolCallMessage(tools: [$tool]),
            new AssistantMessage('latest answer after tool'),
        ) extends FakeAIProvider {
            protected function streamChunks(Message $response): Generator
            {
                if ($response instanceof AssistantMessage) {
                    \Amp\delay(0.2);
                }

                yield from parent::streamChunks($response);

                return $response;
            }
        };
        $agent = self::agentWithReflowingHistory();
        $agent->setAiProvider($provider);
        $terminal = new VirtualTerminal(columns: 80, rows: 24);
        $screen = new ScreenBuffer(80, 24);
        $beforeResult = null;
        $afterResult = null;
        $afterResize = null;
        $afterCompletion = null;
        $resizedScreen = null;

        EventLoop::delay(
            0.03,
            static fn () => $terminal->simulateInput("Use the tool\r"),
        );
        EventLoop::delay(
            0.08,
            static function () use ($screen, $terminal): void {
                self::visibleRows($screen, $terminal);
                $terminal->simulateInput("\x1b[5~");
            },
        );
        EventLoop::delay(
            0.12,
            static function () use (&$beforeResult, $screen, $terminal): void {
                $beforeResult = self::conversationRows($screen, $terminal);
            },
        );
        EventLoop::delay(
            0.28,
            static function () use (&$afterResult, $screen, $terminal): void {
                $afterResult = self::conversationRows($screen, $terminal);
                $terminal->simulateResize(60, 18);
            },
        );
        EventLoop::delay(
            0.34,
            static function () use (&$afterResize, &$resizedScreen, $terminal): void {
                $resizedScreen = new ScreenBuffer(60, 18);
                $afterResize = self::conversationRows(
                    $resizedScreen,
                    $terminal,
                );
            },
        );
        EventLoop::delay(
            0.5,
            static function () use (&$afterCompletion, &$resizedScreen, $terminal): void {
                self::assertInstanceOf(ScreenBuffer::class, $resizedScreen);
                $afterCompletion = self::conversationRows(
                    $resizedScreen,
                    $terminal,
                );
            },
        );
        EventLoop::delay(
            0.6,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new Tui($agent, terminal: $terminal))->run();

        self::assertIsArray($beforeResult);
        self::assertIsArray($afterResult);
        self::assertIsArray($afterResize);
        self::assertIsArray($afterCompletion);
        self::assertSame($beforeResult, $afterResult);
        [$anchorRow, $anchorText] = self::historyAnchor($afterResult);
        self::assertStringContainsString(
            $anchorText,
            $afterResize[$anchorRow],
        );
        self::assertSame($afterResize, $afterCompletion);
        self::assertFalse(self::contains($afterResize, 'expanded-result'));
        self::assertFalse(self::contains($afterResize, 'latest answer after tool'));
    }

    private static function agentWithLongHistory(): Agent
    {
        $history = new InMemoryChatHistory();

        foreach (range(1, 20) as $turn) {
            $history->addMessage(new UserMessage("Question {$turn}"));
            $history->addMessage(new AssistantMessage("Answer {$turn}"));
        }

        $agent = new Agent();
        $agent->setChatHistory($history);

        return $agent;
    }

    private static function agentWithReflowingHistory(): Agent
    {
        $history = new InMemoryChatHistory();

        foreach (range(1, 20) as $turn) {
            $history->addMessage(new UserMessage("Question {$turn}"));
            $answer = $turn < 18
                ? "Answer {$turn} " . str_repeat('earlier context ', 5)
                : "Answer {$turn}";
            $history->addMessage(new AssistantMessage($answer));
        }

        $agent = new Agent();
        $agent->setChatHistory($history);

        return $agent;
    }

    /**
     * @param list<string> $rows
     *
     * @return array{int, string}
     */
    private static function historyAnchor(array $rows): array
    {
        foreach ($rows as $row => $text) {
            if (preg_match('/Answer \\d+/', $text, $match) === 1) {
                return [$row, $match[0]];
            }
        }

        self::fail('No visible History anchor was found.');
    }

    /**
     * @return list<string>
     */
    private static function conversationRows(
        ScreenBuffer $screen,
        VirtualTerminal $terminal,
    ): array {
        return array_slice(self::visibleRows($screen, $terminal), 0, -4);
    }

    /**
     * @return list<string>
     */
    private static function visibleRows(
        ScreenBuffer $screen,
        VirtualTerminal $terminal,
    ): array {
        $screen->write($terminal->consumeOutput());

        return array_values(array_map(rtrim(...), $screen->getLines()));
    }

    /**
     * @param list<string> $rows
     */
    private static function contains(array $rows, string $text): bool
    {
        foreach ($rows as $row) {
            if (str_contains($row, $text)) {
                return true;
            }
        }

        return false;
    }
}
