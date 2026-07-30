<?php

declare(strict_types=1);

namespace NeuronCli\Tests;

use Generator;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Middleware\ToolApproval;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ContentBlocks\VideoContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\Tool;
use NeuronCli\NeuronCli;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\TerminalInterface;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class NeuronCliTest extends TestCase
{
    public function testHostApplicationCanCustomizeConversationBranding(): void
    {
        $terminal = new VirtualTerminal();
        EventLoop::delay(
            0.05,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli(
            new Agent(),
            'Research Agent',
            'Ask about the knowledge base',
            $terminal,
        ))->run();

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());

        self::assertStringContainsString('✦ Research Agent', $display);
        self::assertStringContainsString(
            'Ask about the knowledge base',
            $display,
        );
        self::assertStringContainsString(
            'ready · Enter sends · Shift+Enter adds a line · /exit exits',
            $display,
        );
    }

    public function testConversationOpensAtSafeExistingHistory(): void
    {
        $agent = new Agent();
        $agent->setChatHistory(new ExistingChatHistory([
            new Message(MessageRole::SYSTEM, 'Never reveal this instruction.'),
            new Message(MessageRole::USER, [
                new TextContent('Review these inputs.'),
                new ImageContent(
                    'data:image/png;base64,raw-image-payload',
                    SourceType::BASE64,
                ),
                new FileContent(
                    'raw-file-payload',
                    SourceType::BASE64,
                    filename: "/private/\x00report.pdf",
                ),
                new AudioContent('raw-audio-payload', SourceType::BASE64),
                new VideoContent('raw-video-payload', SourceType::BASE64),
            ]),
            new Message(MessageRole::ASSISTANT, [
                new ReasoningContent('Private chain of thought.'),
                new TextContent('The review is complete.'),
            ]),
            (new AssistantMessage('System content in an assistant class.'))
                ->setRole(MessageRole::SYSTEM),
        ]));
        $terminal = new VirtualTerminal(rows: 60);
        EventLoop::delay(
            0.1,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli($agent, terminal: $terminal))->run();

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());

        self::assertStringContainsString('✦ Neuron AI', $display);
        self::assertStringContainsString('Agent conversation', $display);
        self::assertStringContainsString('❯ Review these inputs.', $display);
        self::assertStringContainsString('● The review is complete.', $display);
        self::assertStringContainsString('[Image]', $display);
        self::assertStringContainsString('[File: report.pdf]', $display);
        self::assertStringContainsString('[Audio]', $display);
        self::assertStringContainsString('[Video]', $display);
        self::assertStringNotContainsString('Never reveal', $display);
        self::assertStringNotContainsString('Private chain', $display);
        self::assertStringNotContainsString(
            'System content in an assistant class.',
            $display,
        );
        self::assertStringNotContainsString('raw-', $display);
        self::assertStringNotContainsString('/private/', $display);
    }

    public function testUserCanSubmitMultilineTextAndWatchStreamedMarkdown(): void
    {
        $intermediateDisplay = null;
        $finalChunk = <<<'MARKDOWN'

- **done**

[Documentation](https://example.test)

| State | Value |
| --- | --- |
| stream | complete |

```php
code();
```
MARKDOWN;
        $provider = new class(
            new AssistantMessage(
                "## Result\n\nmiddle\n" . $finalChunk,
            ),
            $finalChunk,
        ) extends FakeAIProvider {
            public function __construct(
                AssistantMessage $response,
                private readonly string $finalChunk,
            ) {
                parent::__construct($response);
            }

            public function chat(Message ...$messages): Message
            {
                throw new \LogicException('Neuron CLI must stream responses.');
            }

            protected function streamChunks(Message $response): Generator
            {
                yield new TextChunk('stream-test', "## Result\n\n");
                \Amp\delay(0.08);
                yield new TextChunk('stream-test', 'middle');
                \Amp\delay(0.15);
                yield new TextChunk(
                    'stream-test',
                    $this->finalChunk,
                );

                return $response;
            }
        };
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $terminal = new VirtualTerminal(rows: 36);
        EventLoop::queue(
            static function () use ($terminal): void {
                $terminal->simulateInput("   \r");
                $terminal->simulateInput('First line');
                $terminal->simulateInput("\x1b[13;2u");
                $terminal->simulateInput("second line\r");
            },
        );
        EventLoop::delay(
            0.18,
            static function () use (
                &$intermediateDisplay,
                $terminal,
            ): void {
                $terminal->simulateInput("Must not queue\r");
                $intermediateDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
            },
        );
        EventLoop::delay(
            0.6,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli($agent, terminal: $terminal))->run();

        self::assertIsString($intermediateDisplay);
        self::assertStringContainsString('middle', $intermediateDisplay);
        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString('❯ First line', $display);
        self::assertStringContainsString('second line', $display);
        self::assertStringContainsString('● Result', $display);
        self::assertStringContainsString('middle', $display);
        self::assertStringContainsString('done', $display);
        self::assertStringContainsString('Documentation', $display);
        self::assertStringContainsString('State', $display);
        self::assertStringContainsString('complete', $display);
        self::assertStringContainsString('code();', $display);
        self::assertStringContainsString('✶ working…', $display);
        self::assertCount(1, $provider->getRecorded());
        self::assertSame(
            "   First line\nsecond line",
            $provider->getRecorded()[0]->messages[0]->getContent(),
        );
    }

    public function testEmptyStreamHasAnExplicitIndicator(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage());
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $terminal = new VirtualTerminal();
        EventLoop::queue(
            static fn () => $terminal->simulateInput("Hello\r"),
        );
        EventLoop::delay(
            0.15,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli($agent, terminal: $terminal))->run();

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString('Empty response.', $display);
    }

    public function testWhitespaceTextChunksStillCountAsAnEmptyResponse(): void
    {
        $provider = new class(new AssistantMessage()) extends FakeAIProvider {
            protected function streamChunks(Message $response): Generator
            {
                yield new TextChunk('empty-stream', '');
                yield new TextChunk('empty-stream', " \n\t ");

                return $response;
            }
        };
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $terminal = new VirtualTerminal();
        EventLoop::queue(
            static fn () => $terminal->simulateInput("Hello\r"),
        );
        EventLoop::delay(
            0.15,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli($agent, terminal: $terminal))->run();

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString('Empty response.', $display);
    }

    public function testComposerAcceptsAnotherMessageAfterCompletion(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('First answer.'),
            new AssistantMessage('Second answer.'),
        );
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $terminal = new VirtualTerminal(rows: 30);
        EventLoop::queue(
            static fn () => $terminal->simulateInput("First question\r"),
        );
        EventLoop::delay(
            0.1,
            static fn () => $terminal->simulateInput("Second question\r"),
        );
        EventLoop::delay(
            0.25,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli($agent, terminal: $terminal))->run();

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString('First answer.', $display);
        self::assertStringContainsString('Second answer.', $display);
        self::assertCount(2, $provider->getRecorded());
    }

    public function testAgentFailureIsShownWithoutRewritingHistory(): void
    {
        $provider = new class() extends FakeAIProvider {
            public function stream(Message ...$messages): Generator
            {
                throw new \RuntimeException('The request timed out.');
            }
        };
        $history = new InMemoryChatHistory();
        $history->addMessage(new UserMessage('Earlier question.'));
        $history->addMessage(new AssistantMessage('Earlier answer.'));
        $agent = new Agent();
        $agent->setChatHistory($history);
        $agent->setAiProvider($provider);
        $terminal = new VirtualTerminal(rows: 30);
        EventLoop::queue(
            static fn () => $terminal->simulateInput("Try this\r"),
        );
        EventLoop::delay(
            0.1,
            static fn () => $terminal->simulateInput('Draft after failure'),
        );
        EventLoop::delay(
            0.2,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli($agent, terminal: $terminal))->run();

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString(
            'RuntimeException: The request timed out.',
            $display,
        );
        self::assertStringContainsString('Draft after failure', $display);
        self::assertStringNotContainsString('#0 ', $display);
        self::assertSame(
            ['user', 'assistant', 'user'],
            array_map(
                static fn (Message $message): string => $message->getRole(),
                $history->getMessages(),
            ),
        );
        self::assertSame(
            'Try this',
            $history->getMessages()[2]->getContent(),
        );
    }

    public function testHistoricalToolActivityIsCompactAndSafe(): void
    {
        $tool = (new Tool("read_\x00file"))
            ->setCallId('history-call')
            ->setInputs([
                'path' => "first line\nsecond line "
                    . str_repeat('x', 160)
                    . '-argument-tail',
            ])
            ->setResult("complete\tok \xFF" . str_repeat('y', 160)
                . '-result-tail');
        $firstFallback = (new Tool('search'))
            ->setInputs(['q' => 'one'])
            ->setResult('first fallback result');
        $secondFallback = (new Tool('search'))
            ->setInputs(['q' => 'two'])
            ->setResult('second fallback result');
        $agent = new Agent();
        $agent->setChatHistory(new ExistingChatHistory([
            new UserMessage('Read it.'),
            new ToolCallMessage(tools: [
                $tool,
                $firstFallback,
                $secondFallback,
            ]),
            new ToolResultMessage([
                $tool,
                $firstFallback,
                $secondFallback,
            ]),
            new AssistantMessage('Finished.'),
        ]));
        $terminal = new VirtualTerminal(columns: 160, rows: 40);
        EventLoop::delay(
            0.1,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli($agent, terminal: $terminal))->run();

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString(
            '● read_file {"path":"first line\nsecond line',
            $display,
        );
        self::assertStringContainsString('⎿ complete ok', $display);
        self::assertStringContainsString(
            '⎿ first fallback result',
            $display,
        );
        self::assertStringContainsString(
            '⎿ second fallback result',
            $display,
        );
        self::assertStringNotContainsString('-argument-tail', $display);
        self::assertStringNotContainsString('-result-tail', $display);
        self::assertStringNotContainsString("\x00", $display);
        self::assertStringNotContainsString("\xFF", $display);
    }

    public function testLiveToolCallsAreConnectedToTheirResults(): void
    {
        $lookup = (new Tool('lookup'))
            ->setCallId('lookup-call')
            ->setInputs(['q' => 'alpha'])
            ->setCallable(static fn (): string => "alpha\tresult");
        $fallback = (new Tool('fallback'))
            ->setInputs(['q' => 'beta'])
            ->setCallable(static fn (): string => 'beta result');
        $provider = new FakeAIProvider(
            new ToolCallMessage(tools: [$lookup, $fallback]),
            new AssistantMessage('Both tools completed.'),
        );
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $terminal = new VirtualTerminal(rows: 32);
        EventLoop::queue(
            static fn () => $terminal->simulateInput("Run tools\r"),
        );
        EventLoop::delay(
            0.2,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli($agent, terminal: $terminal))->run();

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString(
            '● lookup {"q":"alpha"}',
            $display,
        );
        self::assertStringContainsString('⎿ alpha result', $display);
        self::assertStringContainsString(
            '● fallback {"q":"beta"}',
            $display,
        );
        self::assertStringContainsString('⎿ beta result', $display);
        self::assertStringContainsString('● Both tools completed.', $display);
    }

    public function testSlashCommandsAndEscapeRemainLocal(): void
    {
        $intermediateDisplay = null;
        $forcedExit = false;
        $provider = new FakeAIProvider();
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $terminal = new VirtualTerminal();
        EventLoop::queue(
            static fn () => $terminal->simulateInput("/unknown\r"),
        );
        EventLoop::delay(
            0.06,
            static function () use (
                &$intermediateDisplay,
                $terminal,
            ): void {
                $intermediateDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
            },
        );
        EventLoop::delay(
            0.1,
            static fn () => $terminal->simulateInput("\x1b"),
        );
        EventLoop::delay(
            0.14,
            static fn () => $terminal->simulateInput("/exit\r"),
        );
        EventLoop::delay(
            0.3,
            static function () use (&$forcedExit, $terminal): void {
                $forcedExit = true;
                $terminal->simulateInput("\x03");
            },
        );

        (new NeuronCli($agent, terminal: $terminal))->run();

        self::assertIsString($intermediateDisplay);
        self::assertStringContainsString(
            'Unknown Slash command: /unknown',
            $intermediateDisplay,
        );
        self::assertStringContainsString('/unknown', $intermediateDisplay);
        self::assertFalse($forcedExit);
        self::assertStringContainsString(
            "\x1b[0 q\x1b[?25h",
            $terminal->getOutput(),
        );
        $provider->assertNothingSent();
    }

    public function testPageKeysBrowseAConversationAndReturnToLatest(): void
    {
        $messages = [];

        for ($turn = 1; $turn <= 20; $turn++) {
            $messages[] = new UserMessage("Question {$turn}");
            $messages[] = new AssistantMessage("Answer {$turn}");
        }

        $agent = new Agent();
        $agent->setChatHistory(new ExistingChatHistory($messages));
        $terminal = new VirtualTerminal(rows: 16);
        $initialDisplay = null;
        $scrolledDisplay = null;
        $latestDisplay = null;
        EventLoop::delay(
            0.04,
            static function () use (&$initialDisplay, $terminal): void {
                $initialDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->clearOutput();
                $terminal->simulateInput("\x1b[5~");
            },
        );
        EventLoop::delay(
            0.08,
            static function () use (&$scrolledDisplay, $terminal): void {
                $scrolledDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->clearOutput();
                $terminal->simulateInput("\x1b[6~");
            },
        );
        EventLoop::delay(
            0.12,
            static function () use (&$latestDisplay, $terminal): void {
                $latestDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->simulateInput("\x03");
            },
        );

        (new NeuronCli($agent, terminal: $terminal))->run();

        self::assertIsString($initialDisplay);
        self::assertStringContainsString('Answer 20', $initialDisplay);
        self::assertIsString($scrolledDisplay);
        self::assertStringContainsString('Answer 18', $scrolledDisplay);
        self::assertIsString($latestDisplay);
        self::assertStringContainsString('Answer 20', $latestDisplay);
    }

    public function testStreamKeepsReadingPositionWhileScrolledUp(): void
    {
        $first = implode("\n", array_map(
            static fn (int $line): string => "- anchor {$line}",
            range(1, 12),
        ));
        $second = implode("\n", array_map(
            static fn (int $line): string => "- anchor {$line}",
            range(13, 18),
        ));
        $third = implode("\n", array_map(
            static fn (int $line): string => "- anchor {$line}",
            range(19, 24),
        ));
        $provider = new class(
            new AssistantMessage("{$first}\n{$second}\n{$third}"),
            $first,
            $second,
            $third,
        ) extends FakeAIProvider {
            public function __construct(
                AssistantMessage $response,
                private readonly string $first,
                private readonly string $second,
                private readonly string $third,
            ) {
                parent::__construct($response);
            }

            protected function streamChunks(Message $response): Generator
            {
                yield new TextChunk('anchored-stream', $this->first);
                \Amp\delay(0.15);
                yield new TextChunk(
                    'anchored-stream',
                    "\n" . $this->second,
                );
                \Amp\delay(0.15);
                yield new TextChunk(
                    'anchored-stream',
                    "\n" . $this->third,
                );

                return $response;
            }
        };
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $terminal = new VirtualTerminal(rows: 16);
        $followingDisplay = null;
        $beforeGrowth = null;
        $afterGrowth = null;
        EventLoop::queue(
            static fn () => $terminal->simulateInput("Long answer\r"),
        );
        EventLoop::delay(
            0.07,
            static function () use (&$followingDisplay, $terminal): void {
                $followingDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->clearOutput();
                $terminal->simulateInput("\x1b[5~");
            },
        );
        EventLoop::delay(
            0.11,
            static function () use (&$beforeGrowth, $terminal): void {
                $beforeGrowth = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->clearOutput();
            },
        );
        EventLoop::delay(
            0.22,
            static function () use (&$afterGrowth, $terminal): void {
                $afterGrowth = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
            },
        );
        EventLoop::delay(
            0.4,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli($agent, terminal: $terminal))->run();

        self::assertIsString($followingDisplay);
        self::assertStringContainsString('anchor 12', $followingDisplay);
        self::assertIsString($beforeGrowth);
        self::assertStringContainsString('anchor 8', $beforeGrowth);
        self::assertIsString($afterGrowth);
        self::assertStringContainsString('anchor 8', $afterGrowth);
        self::assertStringNotContainsString('anchor 18', $afterGrowth);
    }

    public function testHumanInterruptionIsExplicitlyUnsupported(): void
    {
        PublishToolCallback::$executed = false;
        $tool = (new Tool('publish'))
            ->setCallId('publish-call')
            ->setInputs(['target' => 'production'])
            ->setCallable(new PublishToolCallback());
        $provider = new FakeAIProvider(new ToolCallMessage(tools: [$tool]));
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $agent->addMiddleware(ToolNode::class, new ToolApproval());
        $terminal = new VirtualTerminal(rows: 28);
        EventLoop::queue(
            static fn () => $terminal->simulateInput("Publish now\r"),
        );
        EventLoop::delay(
            0.1,
            static fn () => $terminal->simulateInput('Draft after interruption'),
        );
        EventLoop::delay(
            0.2,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli($agent, terminal: $terminal))->run();

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString(
            'Human-in-the-loop interruptions are not supported.',
            $display,
        );
        self::assertStringContainsString(
            'Draft after interruption',
            $display,
        );
        self::assertFalse(PublishToolCallback::$executed);
        self::assertCount(1, $provider->getRecorded());
    }

    public function testTerminalStartupFailurePropagatesToHostApplication(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Terminal could not initialize.');

        (new NeuronCli(new Agent(), terminal: new FailingTerminal()))->run();
    }

    public function testDefaultTerminalRejectsNonInteractiveInput(): void
    {
        if (stream_isatty(STDIN)) {
            self::markTestSkipped('This process has an interactive TTY.');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'Neuron CLI requires an interactive TTY.',
        );

        (new NeuronCli(new Agent()))->run();
    }
}

final class ExistingChatHistory extends InMemoryChatHistory
{
    /**
     * @param list<Message> $messages
     */
    public function __construct(array $messages)
    {
        parent::__construct();
        $this->history = $messages;
    }
}

final class PublishToolCallback
{
    public static bool $executed = false;

    public function __invoke(): string
    {
        self::$executed = true;

        return 'published';
    }
}

final class FailingTerminal implements TerminalInterface
{
    public function start(
        callable $onInput,
        callable $onResize,
        callable $onKittyProtocolActivated,
    ): void {
        throw new \LogicException('Terminal could not initialize.');
    }

    public function stop(): void
    {
    }

    public function write(string $data): void
    {
    }

    public function getColumns(): int
    {
        return 80;
    }

    public function getRows(): int
    {
        return 24;
    }

    public function isKittyProtocolActive(): bool
    {
        return false;
    }

    public function moveBy(int $lines): void
    {
    }

    public function hideCursor(): void
    {
    }

    public function showCursor(): void
    {
    }

    public function clearLine(): void
    {
    }

    public function clearFromCursor(): void
    {
    }

    public function clearScreen(): void
    {
    }

    public function setTitle(string $title): void
    {
    }

    public function bell(): void
    {
    }

    public function isVirtual(): bool
    {
        return false;
    }
}
