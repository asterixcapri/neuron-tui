<?php

declare(strict_types=1);

namespace NeuronCli\Tests;

use Closure;
use Generator;
use InvalidArgumentException;
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
use NeuronAI\Testing\RequestRecord;
use NeuronAI\Tools\Tool;
use NeuronCli\Conversation\AbstractCommandKit;
use NeuronCli\Conversation\ChoiceOption;
use NeuronCli\Conversation\Commands\Clear;
use NeuronCli\Conversation\Commands\Help;
use NeuronCli\Conversation\Commands\Leave;
use NeuronCli\Conversation\Commands\SessionKit;
use NeuronCli\Conversation\Commands\Sessions;
use NeuronCli\Conversation\Controls;
use NeuronCli\Conversation\LimitedControls;
use NeuronCli\Conversation\RunsWhileWorking;
use NeuronCli\Conversation\SlashCommand;
use NeuronCli\NeuronCli;
use NeuronCli\Session\InMemorySessionProvider;
use NeuronCli\Session\Session;
use NeuronCli\Session\SessionProvider;
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
            'ready · Enter sends · Shift+Enter adds a line · Ctrl+C exits',
            $display,
        );
    }

    public function testComposerFrameIncludesPromptFromLeftEdge(): void
    {
        $terminal = new VirtualTerminal();
        EventLoop::delay(
            0.05,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli(new Agent(), terminal: $terminal))->run();

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $lines = preg_split('/\r\n|\r|\n/', $display);
        self::assertIsArray($lines);
        $composerLine = null;

        foreach ($lines as $index => $line) {
            if (trim($line) === '❯') {
                $composerLine = $index;
                break;
            }
        }

        self::assertNotNull($composerLine);
        self::assertGreaterThan(0, $composerLine);
        self::assertArrayHasKey($composerLine + 1, $lines);
        self::assertStringStartsWith('─', $lines[$composerLine - 1]);
        self::assertStringStartsWith('─', $lines[$composerLine + 1]);
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

        $output = $terminal->getOutput();
        $display = AnsiUtils::stripAnsiCodes($output);

        self::assertStringContainsString("\x1b[48;2;52;52;52m", $output);
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
        self::assertStringContainsString(
            '✶ Working (0s)',
            $display,
        );
        self::assertCount(1, $provider->getRecorded());
        self::assertSame(
            "   First line\nsecond line",
            $provider->getRecorded()[0]->messages[0]->getContent(),
        );
    }

    public function testAcceptedSubmissionRendersInlineLoadingBeforeProviderStarts(): void
    {
        $displayAtProviderBoundary = null;
        $terminal = new VirtualTerminal();
        $probe = static function () use (
            &$displayAtProviderBoundary,
            $terminal,
        ): void {
            $displayAtProviderBoundary = AnsiUtils::stripAnsiCodes(
                $terminal->getOutput(),
            );
        };
        $provider = new class(
            new AssistantMessage('Done.'),
            $probe,
        ) extends FakeAIProvider {
            public function __construct(
                AssistantMessage $response,
                private readonly Closure $probe,
            ) {
                parent::__construct($response);
            }

            public function stream(Message ...$messages): Generator
            {
                ($this->probe)();

                return parent::stream(...$messages);
            }
        };
        $agent = new Agent();
        $agent->setAiProvider($provider);
        EventLoop::queue(
            static fn () => $terminal->simulateInput('Pending text'),
        );
        EventLoop::delay(
            0.04,
            static function () use ($terminal): void {
                $terminal->clearOutput();
                $terminal->simulateInput("\r");
            },
        );
        EventLoop::delay(
            0.15,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli($agent, terminal: $terminal))->run();

        self::assertIsString($displayAtProviderBoundary);
        self::assertStringContainsString(
            '❯ Pending text',
            $displayAtProviderBoundary,
        );
        self::assertSame(
            1,
            substr_count($displayAtProviderBoundary, 'Pending text'),
        );
        self::assertStringContainsString(
            'Working (0s)',
            $displayAtProviderBoundary,
        );
        $userPosition = strpos(
            $displayAtProviderBoundary,
            '❯ Pending text',
        );
        $loadingPosition = strpos(
            $displayAtProviderBoundary,
            'Working (0s)',
        );
        $composerPosition = strrpos(
            $displayAtProviderBoundary,
            "\n❯",
        );
        self::assertNotFalse($userPosition);
        self::assertNotFalse($loadingPosition);
        self::assertNotFalse($composerPosition);
        self::assertGreaterThan($userPosition, $loadingPosition);
        self::assertLessThan($composerPosition, $loadingPosition);
    }

    public function testBlockingProviderChunksArePaintedIncrementally(): void
    {
        $displayBeforeSecondChunk = null;
        $terminal = new VirtualTerminal();
        $probe = static function () use (
            &$displayBeforeSecondChunk,
            $terminal,
        ): void {
            $displayBeforeSecondChunk = AnsiUtils::stripAnsiCodes(
                $terminal->getOutput(),
            );
        };
        $provider = new class(
            new AssistantMessage('first chunk second chunk'),
            $probe,
        ) extends FakeAIProvider {
            public function __construct(
                AssistantMessage $response,
                private readonly Closure $probe,
            ) {
                parent::__construct($response);
            }

            protected function streamChunks(Message $response): Generator
            {
                yield new TextChunk('blocking-stream', 'first chunk');
                ($this->probe)();
                yield new TextChunk(
                    'blocking-stream',
                    ' second chunk',
                );

                return $response;
            }
        };
        $agent = new Agent();
        $agent->setAiProvider($provider);
        EventLoop::queue(
            static fn () => $terminal->simulateInput("Stream it\r"),
        );
        EventLoop::delay(
            0.15,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli($agent, terminal: $terminal))->run();

        self::assertIsString($displayBeforeSecondChunk);
        self::assertStringContainsString(
            '● first chunk',
            $displayBeforeSecondChunk,
        );
        self::assertStringNotContainsString(
            'second chunk',
            $displayBeforeSecondChunk,
        );
    }

    public function testInlineWorkingIndicatorAnimatesBeforeFirstChunk(): void
    {
        $provider = new class(
            new AssistantMessage('Response started.'),
        ) extends FakeAIProvider {
            protected function streamChunks(Message $response): Generator
            {
                \Amp\delay(0.28);
                yield new TextChunk(
                    'delayed-stream',
                    'Response started.',
                );

                return $response;
            }
        };
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $terminal = new VirtualTerminal();
        $animatedDisplay = null;
        EventLoop::queue(
            static fn () => $terminal->simulateInput("Wait for it\r"),
        );
        EventLoop::delay(
            0.06,
            static fn () => $terminal->clearOutput(),
        );
        EventLoop::delay(
            0.22,
            static function () use (
                &$animatedDisplay,
                $terminal,
            ): void {
                $animatedDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
            },
        );
        EventLoop::delay(
            0.4,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli($agent, terminal: $terminal))->run();

        self::assertIsString($animatedDisplay);
        self::assertMatchesRegularExpression(
            '/[✸✹✺✷] Working \\(0s\\)/u',
            $animatedDisplay,
        );
        self::assertStringNotContainsString(
            'Response started.',
            $animatedDisplay,
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

    public function testComposerQueuesAnotherMessageWhileWorking(): void
    {
        $queuedDisplay = null;
        $provider = new class(
            new AssistantMessage('First answer.'),
            new AssistantMessage('Second answer.'),
        ) extends FakeAIProvider {
            private int $turn = 0;

            protected function streamChunks(Message $response): Generator
            {
                ++$this->turn;

                if ($this->turn === 1) {
                    \Amp\delay(0.2);
                }

                yield new TextChunk(
                    'queued-stream',
                    $response->getContent() ?? '',
                );

                return $response;
            }
        };
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $terminal = new VirtualTerminal(rows: 30);
        EventLoop::queue(
            static fn () => $terminal->simulateInput("First question\r"),
        );
        EventLoop::delay(
            0.05,
            static fn () => $terminal->simulateInput("Second question\r"),
        );
        EventLoop::delay(
            0.12,
            static function () use (
                &$queuedDisplay,
                $terminal,
            ): void {
                $queuedDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
            },
        );
        EventLoop::delay(
            0.4,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli($agent, terminal: $terminal))->run();

        self::assertIsString($queuedDisplay);
        self::assertStringContainsString(
            'Messages to be submitted after the current turn',
            $queuedDisplay,
        );
        self::assertStringContainsString('↳ Second question', $queuedDisplay);
        self::assertStringNotContainsString('First answer.', $queuedDisplay);
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

    public function testToolCallIsPaintedBeforeExecutionBegins(): void
    {
        $displayAtExecution = null;
        $terminal = new VirtualTerminal(rows: 32);
        $tool = (new Tool('slow_lookup'))
            ->setCallId('slow-lookup-call')
            ->setInputs(['q' => 'alpha'])
            ->setCallable(
                static function () use (
                    &$displayAtExecution,
                    $terminal,
                ): string {
                    $displayAtExecution = AnsiUtils::stripAnsiCodes(
                        $terminal->getOutput(),
                    );

                    return 'alpha result';
                },
            );
        $provider = new FakeAIProvider(
            new ToolCallMessage(tools: [$tool]),
            new AssistantMessage('Tool completed.'),
        );
        $agent = new Agent();
        $agent->setAiProvider($provider);
        EventLoop::queue(
            static fn () => $terminal->simulateInput("Run tool\r"),
        );
        EventLoop::delay(
            0.2,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli($agent, terminal: $terminal))->run();

        self::assertIsString($displayAtExecution);
        self::assertStringContainsString(
            '● slow_lookup {"q":"alpha"}',
            $displayAtExecution,
        );
        self::assertStringContainsString('⎿ Running…', $displayAtExecution);
        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString('⎿ alpha result', $display);
        self::assertMatchesRegularExpression(
            '/Done in (?:<1|\d+)s/',
            $display,
        );
    }

    public function testWorkingIndicatorRemainsAnimatedDuringToolExecution(): void
    {
        $displayDuringExecution = null;
        $terminal = new VirtualTerminal(rows: 32);
        $tool = (new Tool('slow_write'))
            ->setCallId('slow-write-call')
            ->setInputs(['file_path' => 'example.txt'])
            ->setCallable(
                static function (): string {
                    \Amp\delay(0.3);

                    return 'written';
                },
            );
        $provider = new FakeAIProvider(
            new ToolCallMessage(tools: [$tool]),
            new AssistantMessage('File written.'),
        );
        $agent = new Agent();
        $agent->setAiProvider($provider);
        EventLoop::queue(
            static fn () => $terminal->simulateInput("Write file\r"),
        );
        EventLoop::delay(
            0.06,
            static fn () => $terminal->clearOutput(),
        );
        EventLoop::delay(
            0.2,
            static function () use (
                &$displayDuringExecution,
                $terminal,
            ): void {
                $displayDuringExecution = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
            },
        );
        EventLoop::delay(
            0.45,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli($agent, terminal: $terminal))->run();

        self::assertIsString($displayDuringExecution);
        self::assertMatchesRegularExpression(
            '/[✸✹✺✷] Working \\(0s\\)/u',
            $displayDuringExecution,
        );
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
            static function () use ($terminal): void {
                // The unknown name is still in the composer under the band
                // that says nothing matches: the first Escape takes the
                // band away, the second the name.
                $terminal->simulateInput("\x1b");
                $terminal->simulateInput("\x1b");
            },
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

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: [new Leave()],
        ))->run();

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

    public function testACommandFollowedByArgumentsIsStillThatCommand(): void
    {
        $afterSessions = null;
        $afterClear = null;
        $forcedExit = false;
        $provider = new FakeAIProvider(new AssistantMessage('An answer.'));
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $terminal = new VirtualTerminal(rows: 30);
        EventLoop::queue(
            static fn () => $terminal->simulateInput("/clear now\r"),
        );
        EventLoop::delay(
            0.06,
            static fn () => $terminal->simulateInput("A question\r"),
        );
        EventLoop::delay(
            0.2,
            static function () use ($terminal): void {
                $terminal->clearOutput();
                $terminal->simulateInput("/sessions now\r");
            },
        );
        EventLoop::delay(
            0.3,
            static function () use (&$afterSessions, $terminal): void {
                $afterSessions = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                // Escape leaves the picker and the composer is free again.
                $terminal->simulateInput("\x1b");
                $terminal->clearOutput();
                $terminal->simulateInput("/clear now\r");
            },
        );
        EventLoop::delay(
            0.4,
            static function () use (&$afterClear, $terminal): void {
                $afterClear = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->simulateInput("/exit now\r");
            },
        );
        EventLoop::delay(
            0.6,
            static function () use (&$forcedExit, $terminal): void {
                $forcedExit = true;
                $terminal->simulateInput("\x03");
            },
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: self::sessionCommands(new InMemorySessionProvider()),
        ))->run();

        // `/sessions now` opens the picker on the Session being written in.
        self::assertIsString($afterSessions);
        self::assertStringNotContainsString(
            'Unknown Slash command',
            $afterSessions,
        );
        self::assertStringContainsString('A question', $afterSessions);
        // `/clear now` starts a fresh Session, so the answer goes off the
        // screen instead of an unknown command reaching the conversation.
        self::assertIsString($afterClear);
        self::assertStringNotContainsString(
            'Unknown Slash command',
            $afterClear,
        );
        self::assertStringNotContainsString('An answer.', $afterClear);
        // `/exit now` leaves, so Ctrl+C was never needed.
        self::assertFalse($forcedExit);
    }

    /**
     * Mounts a command of the Host Application's own and runs it.
     */
    public function testAMountedCommandRunsWithWhatWasTypedAfterItsName(): void
    {
        $arguments = null;
        $provider = new FakeAIProvider();
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $terminal = new VirtualTerminal(rows: 30);
        $command = $this->commandThat(
            static function (
                Controls $controls,
                string $typed,
            ) use (&$arguments): void {
                $arguments = $typed;
                $controls->say('The command ran.');
            },
        );
        EventLoop::queue(
            static fn () => $terminal->simulateInput("/probe two words\r"),
        );
        EventLoop::delay(
            0.2,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: [$command],
        ))->run();

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertSame('two words', $arguments);
        self::assertStringContainsString('The command ran.', $display);
        self::assertStringNotContainsString('Unknown Slash command', $display);
        $provider->assertNothingSent();
    }

    public function testWhatACommandSaysAndWarnsReachesTheConversation(): void
    {
        $provider = new FakeAIProvider();
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $terminal = new VirtualTerminal(rows: 30);
        $command = $this->commandThat(
            static function (Controls $controls, string $arguments): void {
                $controls->say('Everything was in order.');
                $controls->warn('Except for one thing.');
            },
        );
        EventLoop::queue(
            static fn () => $terminal->simulateInput("/probe\r"),
        );
        EventLoop::delay(
            0.2,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: [$command],
        ))->run();

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString(
            'Everything was in order.',
            $display,
        );
        self::assertStringContainsString('Except for one thing.', $display);
        $provider->assertNothingSent();
    }

    public function testThePromptACommandPutsToTheAgentIsAnsweredOnScreen(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('An answer.'));
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $terminal = new VirtualTerminal(rows: 30);
        $command = $this->commandThat(
            static function (Controls $controls, string $arguments): void {
                $controls->ask('Review ' . $arguments . '.');
            },
        );
        EventLoop::queue(
            static fn () => $terminal->simulateInput("/probe this diff\r"),
        );
        EventLoop::delay(
            0.4,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: [$command],
        ))->run();

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString('❯ Review this diff.', $display);
        self::assertStringContainsString('An answer.', $display);
        self::assertCount(1, $provider->getRecorded());
        self::assertSame(
            'Review this diff.',
            $provider->getRecorded()[0]->messages[0]->getContent(),
        );
    }

    public function testACommandReachesTheAgentToChangeProviderInstructionsAndTools(): void
    {
        $abandoned = new FakeAIProvider(new AssistantMessage('The old one.'));
        $chosen = new FakeAIProvider(new AssistantMessage('The new one.'));
        $agent = new Agent();
        $agent->setAiProvider($abandoned);
        $terminal = new VirtualTerminal(rows: 30);
        $command = $this->commandThat(
            static function (
                Controls $controls,
                string $arguments,
            ) use ($chosen): void {
                $controls->agent()
                    ->setAiProvider($chosen)
                    ->setInstructions('Answer in one word.')
                    ->addTool(new Tool('read_file'));
            },
        );
        EventLoop::queue(
            static fn () => $terminal->simulateInput("/probe\r"),
        );
        EventLoop::delay(
            0.1,
            static fn () => $terminal->simulateInput("A question\r"),
        );
        EventLoop::delay(
            0.4,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: [$command],
        ))->run();

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString('The new one.', $display);
        self::assertStringNotContainsString('The old one.', $display);
        $abandoned->assertNothingSent();
        $chosen->assertCallCount(1);
        $chosen->assertSystemPrompt('Answer in one word.');
        $chosen->assertToolsConfigured(['read_file']);
    }

    public function testAnotherAgentAnswersFromHereOnWithTheSameConversation(): void
    {
        $abandoned = new FakeAIProvider(new AssistantMessage('The old one.'));
        $chosen = new FakeAIProvider(new AssistantMessage('The new one.'));
        $agent = new Agent();
        $agent->setAiProvider($abandoned);
        $successor = new Agent();
        $successor->setAiProvider($chosen);
        $terminal = new VirtualTerminal(rows: 30);
        $command = $this->commandThat(
            static function (
                Controls $controls,
                string $arguments,
            ) use ($successor): void {
                $controls->useAgent($successor);
            },
        );
        EventLoop::queue(
            static fn () => $terminal->simulateInput("A question\r"),
        );
        EventLoop::delay(
            0.3,
            static fn () => $terminal->simulateInput("/probe\r"),
        );
        EventLoop::delay(
            0.4,
            static fn () => $terminal->simulateInput("Another question\r"),
        );
        EventLoop::delay(
            0.7,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: [$command],
        ))->run();

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        // The conversation held by the Agent that answered first is still on
        // the screen, and it is the new Agent that carries it on.
        self::assertStringContainsString('The old one.', $display);
        self::assertStringContainsString('The new one.', $display);
        $abandoned->assertCallCount(1);
        $chosen->assertCallCount(1);
        $carried = $chosen->getRecorded()[0]->messages;
        self::assertSame(
            ['A question', 'The old one.', 'Another question'],
            array_map(
                static fn (Message $message): mixed => $message->getContent(),
                $carried,
            ),
        );
    }

    public function testACommandCanInstallAnotherHistoryOnTheAgentItChose(): void
    {
        $abandoned = new FakeAIProvider(new AssistantMessage('The old one.'));
        $chosen = new FakeAIProvider(new AssistantMessage('The new one.'));
        $agent = new Agent();
        $agent->setAiProvider($abandoned);
        $successor = new Agent();
        $successor->setAiProvider($chosen);
        $terminal = new VirtualTerminal(rows: 30);
        $command = $this->commandThat(
            static function (
                Controls $controls,
                string $arguments,
            ) use ($successor): void {
                $controls->useAgent($successor);
                $controls->agent()->setChatHistory(new InMemoryChatHistory());
            },
        );
        EventLoop::queue(
            static fn () => $terminal->simulateInput("A question\r"),
        );
        EventLoop::delay(
            0.3,
            static fn () => $terminal->simulateInput("/probe\r"),
        );
        EventLoop::delay(
            0.4,
            static fn () => $terminal->simulateInput("Another question\r"),
        );
        EventLoop::delay(
            0.7,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: [$command],
        ))->run();

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString('The new one.', $display);
        $chosen->assertCallCount(1);
        $carried = $chosen->getRecorded()[0]->messages;
        self::assertSame(
            ['Another question'],
            array_map(
                static fn (Message $message): mixed => $message->getContent(),
                $carried,
            ),
        );
    }

    public function testACommandCanLeaveTheTerminal(): void
    {
        $forcedExit = false;
        $agent = new Agent();
        $agent->setAiProvider(new FakeAIProvider());
        $terminal = new VirtualTerminal(rows: 30);
        $command = $this->commandThat(
            static function (Controls $controls, string $arguments): void {
                $controls->stop();
            },
            '/quit',
        );
        EventLoop::queue(
            static fn () => $terminal->simulateInput("/quit\r"),
        );
        EventLoop::delay(
            0.3,
            static function () use (&$forcedExit, $terminal): void {
                $forcedExit = true;
                $terminal->simulateInput("\x03");
            },
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: [$command],
        ))->run();

        self::assertFalse($forcedExit);
    }

    public function testACommandThatFailsLeavesAnErrorLineAndAUsableTerminal(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('An answer.'));
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $terminal = new VirtualTerminal(rows: 30);
        $command = $this->commandThat(
            static function (Controls $controls, string $arguments): void {
                throw new \RuntimeException('The command broke.');
            },
        );
        EventLoop::queue(
            static fn () => $terminal->simulateInput("/probe\r"),
        );
        EventLoop::delay(
            0.15,
            static fn () => $terminal->simulateInput("A question\r"),
        );
        EventLoop::delay(
            0.45,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: [$command],
        ))->run();

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString(
            'RuntimeException: The command broke.',
            $display,
        );
        self::assertStringNotContainsString('#0 ', $display);
        self::assertStringContainsString('An answer.', $display);
    }

    public function testACommandThatFailsAfterChangingConversationSaysSoOnTheNewOne(): void
    {
        $agent = new Agent();
        $agent->setAiProvider(new FakeAIProvider());
        $agent->setChatHistory(new ExistingChatHistory([
            new UserMessage('Earlier question.'),
            new AssistantMessage('Earlier answer.'),
        ]));
        $terminal = new VirtualTerminal(rows: 24);
        $command = $this->commandThat(
            static function (Controls $controls, string $arguments): void {
                $controls->agent()->setChatHistory(new InMemoryChatHistory());

                throw new \RuntimeException('The command broke.');
            },
        );
        EventLoop::queue(
            static fn () => $terminal->simulateInput("/probe\r"),
        );
        EventLoop::delay(
            0.2,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: [$command],
        ))->run();

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());

        // The screen is reconciled first, so the line of error is left on the
        // conversation the command walked away with rather than wiped by the
        // repaint that follows it.
        self::assertStringContainsString(
            'RuntimeException: The command broke.',
            $display,
        );
        self::assertStringNotContainsString('Earlier question.', $display);
    }

    public function testTwoCommandsAnsweringToTheSameNameStopTheConstruction(): void
    {
        $doNothing = static function (
            Controls $controls,
            string $arguments,
        ): void {
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Two Slash commands answer to /probe.',
        );

        new NeuronCli(
            new Agent(),
            terminal: new VirtualTerminal(),
            commands: [
                $this->commandThat($doNothing),
                $this->commandThat($doNothing),
            ],
        );
    }

    public function testNothingIsMountedUnlessAHostApplicationNamesIt(): void
    {
        $forcedExit = false;
        $provider = new FakeAIProvider();
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $terminal = new VirtualTerminal(rows: 30);
        EventLoop::queue(
            static fn () => $terminal->simulateInput("/clear\r"),
        );
        EventLoop::delay(
            0.08,
            static function () use ($terminal): void {
                // An unknown name stays in the composer under the band that
                // says nothing matches, so the first Escape takes the band
                // away and the second the name.
                $terminal->simulateInput("\x1b");
                $terminal->simulateInput("\x1b");
                $terminal->simulateInput("/sessions\r");
            },
        );
        EventLoop::delay(
            0.16,
            static function () use ($terminal): void {
                $terminal->simulateInput("\x1b");
                $terminal->simulateInput("\x1b");
                $terminal->simulateInput("/exit\r");
            },
        );
        EventLoop::delay(
            0.3,
            static function () use (&$forcedExit, $terminal): void {
                $forcedExit = true;
                $terminal->simulateInput("\x03");
            },
        );

        (new NeuronCli($agent, terminal: $terminal))->run();

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());

        self::assertStringContainsString(
            'Unknown Slash command: /clear',
            $display,
        );
        self::assertStringContainsString(
            'Unknown Slash command: /sessions',
            $display,
        );
        self::assertStringContainsString(
            'Unknown Slash command: /exit',
            $display,
        );
        // Without the command that leaves, Ctrl+C is the way out.
        self::assertTrue($forcedExit);
        $provider->assertNothingSent();
    }

    /**
     * A provided command is mounted the way one of the Host Application's own
     * is, and under whatever name the Host Application prefers.
     */
    public function testAProvidedCommandAnswersToTheNameItWasGiven(): void
    {
        $forcedExit = false;
        $unknownDisplay = null;
        $wipedDisplay = null;
        $agent = new Agent();
        $agent->setAiProvider(new FakeAIProvider());
        $agent->setChatHistory(new ExistingChatHistory([
            new UserMessage('Earlier question.'),
            new AssistantMessage('Earlier answer.'),
        ]));
        $terminal = new VirtualTerminal(rows: 24);
        EventLoop::queue(
            static fn () => $terminal->simulateInput("/clear\r"),
        );
        EventLoop::delay(
            0.08,
            static function () use (&$unknownDisplay, $terminal): void {
                $unknownDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->simulateInput("\x1b");
                $terminal->simulateInput("\x1b");
                $terminal->clearOutput();
                $terminal->simulateInput("/wipe\r");
            },
        );
        EventLoop::delay(
            0.16,
            static function () use (&$wipedDisplay, $terminal): void {
                $wipedDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->simulateInput("/quit\r");
            },
        );
        EventLoop::delay(
            0.4,
            static function () use (&$forcedExit, $terminal): void {
                $forcedExit = true;
                $terminal->simulateInput("\x03");
            },
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: [
                new Clear(new InMemorySessionProvider(), '/wipe'),
                new Leave('/quit'),
            ],
        ))->run();

        // The name it was given is the only name it answers to.
        self::assertIsString($unknownDisplay);
        self::assertStringContainsString(
            'Unknown Slash command: /clear',
            $unknownDisplay,
        );
        self::assertStringContainsString(
            'Earlier question.',
            $unknownDisplay,
        );
        // `/wipe` behaves as `/clear` always did.
        self::assertIsString($wipedDisplay);
        self::assertStringNotContainsString(
            'Earlier question.',
            $wipedDisplay,
        );
        self::assertSame([], $agent->getChatHistory()->getMessages());
        // `/quit` behaves as `/exit` always did.
        self::assertFalse($forcedExit);
    }

    /**
     * Typing `/help` lists what can be typed here, itself included.
     */
    public function testHelpListsTheMountedCommandsWithTheirDescriptions(): void
    {
        $provider = new FakeAIProvider();
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $terminal = new VirtualTerminal(rows: 30);
        $command = $this->commandThat(
            static function (Controls $controls, string $arguments): void {
            },
        );
        EventLoop::queue(
            static fn () => $terminal->simulateInput("/help\r"),
        );
        EventLoop::delay(
            0.2,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: [new Help(), new Leave(), $command],
        ))->run();

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());

        // The list carries the command that shows it, ...
        self::assertStringContainsString(
            '/help — Lists what can be typed here.',
            $display,
        );
        // ... the other commands this library ships, ...
        self::assertStringContainsString(
            '/exit — Closes the Conversation TUI.',
            $display,
        );
        // ... and the ones the Host Application wrote itself.
        self::assertStringContainsString(
            '/probe — Does what the test says.',
            $display,
        );
        self::assertStringNotContainsString('Unknown Slash command', $display);
        $provider->assertNothingSent();
    }

    /**
     * A name no mounted command answers to is unknown, `/help` included.
     */
    public function testATerminalWithoutHelpMountedDoesNotAnswerToIt(): void
    {
        $provider = new FakeAIProvider();
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $terminal = new VirtualTerminal(rows: 30);
        EventLoop::queue(
            static fn () => $terminal->simulateInput("/help\r"),
        );
        EventLoop::delay(
            0.2,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: [new Leave()],
        ))->run();

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());

        self::assertStringContainsString(
            'Unknown Slash command: /help',
            $display,
        );
        $provider->assertNothingSent();
    }

    public function testTheScreenShowsTheConversationACommandInstalled(): void
    {
        $agent = new Agent();
        $agent->setAiProvider(new FakeAIProvider());
        $terminal = new VirtualTerminal(rows: 24);
        $command = $this->commandThat(
            static function (Controls $controls, string $arguments): void {
                $controls->agent()->setChatHistory(new ExistingChatHistory([
                    new UserMessage('A restored question.'),
                    new AssistantMessage('A restored answer.'),
                ]));
            },
        );
        EventLoop::queue(
            static fn () => $terminal->simulateInput("A question\r"),
        );
        EventLoop::delay(
            0.25,
            static function () use ($terminal): void {
                $terminal->clearOutput();
                $terminal->simulateInput("/probe\r");
            },
        );
        EventLoop::delay(
            0.4,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: [$command],
        ))->run();

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());

        // The command said nothing about what it had done, and the screen
        // says it anyway.
        self::assertStringContainsString('❯ A restored question.', $display);
        self::assertStringContainsString('● A restored answer.', $display);
        self::assertStringNotContainsString('A question', $display);
    }

    public function testACommandThatLeavesTheConversationAloneLeavesTheScreenAlone(): void
    {
        $agent = new Agent();
        $agent->setAiProvider(new FakeAIProvider(
            new AssistantMessage('An answer.'),
        ));
        $terminal = new VirtualTerminal(rows: 30);
        $command = $this->commandThat(
            static function (Controls $controls, string $arguments): void {
                $controls->say('The command ran.');
            },
        );
        EventLoop::queue(
            static fn () => $terminal->simulateInput("A question\r"),
        );
        EventLoop::delay(
            0.25,
            static fn () => $terminal->simulateInput("/probe\r"),
        );
        EventLoop::delay(
            0.4,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: [$command],
        ))->run();

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());

        self::assertStringContainsString('❯ A question', $display);
        self::assertStringContainsString('● An answer.', $display);
        self::assertStringContainsString('The command ran.', $display);
    }

    public function testAMountedCommandIsRefusedWhileTheAgentIsWorking(): void
    {
        $refusedDisplay = null;
        $ran = false;
        $provider = new class(
            new AssistantMessage('A slow answer.'),
        ) extends FakeAIProvider {
            protected function streamChunks(Message $response): Generator
            {
                \Amp\delay(0.4);
                yield new TextChunk('slow-stream', 'A slow answer.');

                return $response;
            }
        };
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $terminal = new VirtualTerminal(rows: 24);
        $command = $this->commandThat(
            static function (
                Controls $controls,
                string $arguments,
            ) use (&$ran): void {
                $ran = true;
            },
        );
        EventLoop::queue(
            static fn () => $terminal->simulateInput("A question\r"),
        );
        EventLoop::delay(
            0.06,
            static fn () => $terminal->simulateInput("/probe\r"),
        );
        EventLoop::delay(
            0.12,
            static function () use (&$refusedDisplay, $terminal): void {
                $refusedDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->simulateInput("\x03");
            },
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: [$command],
        ))->run();

        self::assertIsString($refusedDisplay);
        self::assertStringContainsString(
            '/probe is refused while the Agent is working',
            $refusedDisplay,
        );
        self::assertFalse($ran);
    }

    /**
     * A command that says it runs while the Agent is working is carried out
     * there and then, and the answer on its way is none the worse for it.
     */
    public function testACommandThatRunsWhileWorkingIsCarriedOutMidTurn(): void
    {
        $ran = false;
        $midTurnDisplay = null;
        $provider = new class(
            new AssistantMessage('A slow answer.'),
        ) extends FakeAIProvider {
            protected function streamChunks(Message $response): Generator
            {
                \Amp\delay(0.5);
                yield new TextChunk('slow-stream', 'A slow answer.');

                return $response;
            }
        };
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $terminal = new VirtualTerminal(rows: 24);
        $command = $this->commandThatRunsWhileWorking(
            static function (
                LimitedControls $controls,
                string $arguments,
            ) use (&$ran): void {
                $ran = true;
                $controls->say('The command ran mid-turn.');
            },
        );
        EventLoop::queue(
            static fn () => $terminal->simulateInput("A question\r"),
        );
        EventLoop::delay(
            0.06,
            static fn () => $terminal->simulateInput("/probe\r"),
        );
        EventLoop::delay(
            0.12,
            static function () use (&$midTurnDisplay, $terminal): void {
                $midTurnDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
            },
        );
        EventLoop::delay(
            0.9,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: [$command],
        ))->run();

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());

        self::assertTrue($ran);
        self::assertIsString($midTurnDisplay);
        self::assertStringContainsString(
            'The command ran mid-turn.',
            $midTurnDisplay,
        );
        self::assertStringNotContainsString('is refused', $midTurnDisplay);
        // The answer under way arrives all the same, on the conversation the
        // command ran in, and what the command said is still there under it.
        self::assertStringContainsString('❯ A question', $display);
        self::assertStringContainsString('● A slow answer.', $display);
        self::assertStringContainsString('The command ran mid-turn.', $display);
    }

    /**
     * Asking for help is one of the two shipped commands a turn under way
     * does not hold back, and leaving is the other.
     */
    public function testHelpAndLeaveAnswerWhileTheAgentIsWorking(): void
    {
        $forcedExit = false;
        $midTurnDisplay = null;
        $provider = new class(
            new AssistantMessage('A slow answer.'),
        ) extends FakeAIProvider {
            protected function streamChunks(Message $response): Generator
            {
                \Amp\delay(0.5);
                yield new TextChunk('slow-stream', 'A slow answer.');

                return $response;
            }
        };
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $terminal = new VirtualTerminal(rows: 24);
        EventLoop::queue(
            static fn () => $terminal->simulateInput("A question\r"),
        );
        EventLoop::delay(
            0.06,
            static fn () => $terminal->simulateInput("/help\r"),
        );
        EventLoop::delay(
            0.12,
            static function () use (&$midTurnDisplay, $terminal): void {
                $midTurnDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->simulateInput("/exit\r");
            },
        );
        EventLoop::delay(
            0.9,
            static function () use (&$forcedExit, $terminal): void {
                $forcedExit = true;
                $terminal->simulateInput("\x03");
            },
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: [new Help(), new Leave()],
        ))->run();

        self::assertIsString($midTurnDisplay);
        self::assertStringContainsString(
            '/help — Lists what can be typed here.',
            $midTurnDisplay,
        );
        self::assertStringContainsString(
            '/exit — Closes the Conversation TUI.',
            $midTurnDisplay,
        );
        self::assertFalse($forcedExit);
    }

    /**
     * A kit carries commands of both kinds, and what arrived in one is
     * mounted like anything else — the mid-turn permission included.
     */
    public function testAKitCanCarryACommandThatRunsWhileWorking(): void
    {
        $agent = new Agent();
        $agent->setAiProvider(new FakeAIProvider());
        $terminal = new VirtualTerminal(rows: 24);
        $kit = new class() extends AbstractCommandKit {
            protected function provide(): array
            {
                return [new Help(), new Leave()];
            }
        };
        EventLoop::queue(
            static fn () => $terminal->simulateInput("/help\r"),
        );
        EventLoop::delay(
            0.06,
            static fn () => $terminal->simulateInput("/exit\r"),
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: [$kit],
        ))->run();

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());

        self::assertStringContainsString(
            '/help — Lists what can be typed here.',
            $display,
        );
        self::assertStringContainsString(
            '/exit — Closes the Conversation TUI.',
            $display,
        );
    }

    /**
     * A command that offers a list of its own gets back the key of the line
     * a person chose, whatever those lines stand for.
     */
    public function testACommandOffersAListAndReceivesTheChosenKey(): void
    {
        $chosen = 'nothing yet';
        $pickerDisplay = null;
        $agent = new Agent();
        $agent->setAiProvider(new FakeAIProvider());
        $terminal = new VirtualTerminal(rows: 24);
        $command = $this->commandThat(
            static function (
                Controls $controls,
                string $arguments,
            ) use (&$chosen): void {
                $chosen = $controls->choose('Models', [
                    new ChoiceOption('haiku', 'Claude Haiku'),
                    new ChoiceOption('007', 'Claude Opus'),
                ]);
                $controls->say('Chosen: ' . ($chosen ?? 'nothing'));
            },
        );
        EventLoop::delay(
            0.04,
            static fn () => $terminal->simulateInput("/probe\r"),
        );
        EventLoop::delay(
            0.1,
            static function () use (&$pickerDisplay, $terminal): void {
                // Captured while the command is still waiting: the list is on
                // screen, so the TUI went on painting instead of stopping.
                $pickerDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->simulateInput("\x1b[A");
            },
        );
        EventLoop::delay(
            0.16,
            static fn () => $terminal->simulateInput("\r"),
        );
        EventLoop::delay(
            0.26,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: [$command],
        ))->run();

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());

        self::assertIsString($pickerDisplay);
        self::assertStringContainsString('Models (1 of 2)', $pickerDisplay);
        self::assertStringContainsString(
            '↑↓ move · Enter chooses · Escape cancels',
            $pickerDisplay,
        );
        self::assertStringContainsString('Claude Haiku', $pickerDisplay);
        self::assertStringContainsString('Claude Opus', $pickerDisplay);
        self::assertStringNotContainsString('007', $pickerDisplay);
        self::assertSame('007', $chosen);
        self::assertStringContainsString('Chosen: 007', $display);
        self::assertStringContainsString('ready · Enter sends', $display);
    }

    public function testPickerPanelReplacesWritingControlsAndUpdatesCounter(): void
    {
        $chosen = null;
        $initialDisplay = null;
        $movedDisplay = null;
        $agent = new Agent();
        $agent->setAiProvider(new FakeAIProvider());
        $terminal = new VirtualTerminal(columns: 48, rows: 30);
        $command = $this->commandThat(
            static function (
                Controls $controls,
                string $arguments,
            ) use (&$chosen): void {
                $controls->say('History remains visible.');
                $chosen = $controls->choose(
                    'Models',
                    [
                        new ChoiceOption('haiku', 'Claude Haiku'),
                        new ChoiceOption('opus', 'Claude Opus'),
                    ],
                    'Choose the model used for the next response.',
                );
            },
        );
        EventLoop::delay(
            0.04,
            static fn () => $terminal->simulateInput("/probe\r"),
        );
        EventLoop::delay(
            0.1,
            static function () use ($terminal): void {
                $terminal->clearOutput();
                $terminal->simulateResize(48, 30);
            },
        );
        EventLoop::delay(
            0.14,
            static function () use (&$initialDisplay, $terminal): void {
                $initialDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->clearOutput();
                $terminal->simulateInput("\x1b[B");
            },
        );
        EventLoop::delay(
            0.2,
            static function () use (&$movedDisplay, $terminal): void {
                $movedDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->simulateInput("\r");
            },
        );
        EventLoop::delay(
            0.3,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: [$command],
        ))->run();

        self::assertIsString($initialDisplay);
        self::assertStringContainsString('History remains visible.', $initialDisplay);
        self::assertStringContainsString('Models (1 of 2)', $initialDisplay);
        self::assertStringContainsString(
            'Choose the model used for the next response.',
            $initialDisplay,
        );
        self::assertStringContainsString(
            '↑↓ move · Enter chooses · Escape cancels',
            $initialDisplay,
        );
        self::assertStringNotContainsString('ready · Enter sends', $initialDisplay);
        self::assertStringNotContainsString('suggesting ·', $initialDisplay);
        self::assertDoesNotMatchRegularExpression('/^\s*❯\s*$/m', $initialDisplay);
        $initialLines = explode("\n", str_replace("\r", '', $initialDisplay));
        $headingLine = array_find_key(
            $initialLines,
            static fn (string $line): bool => str_contains($line, 'Models (1 of 2)'),
        );
        self::assertIsInt($headingLine);
        self::assertMatchesRegularExpression(
            '/^─+$/u',
            trim($initialLines[$headingLine - 2]),
        );
        self::assertIsString($movedDisplay);
        self::assertStringContainsString('Models (2 of 2)', $movedDisplay);
        self::assertStringContainsString('→ Claude Opus', $movedDisplay);
        self::assertSame('opus', $chosen);
    }

    public function testPickerDescriptionUsesNoRowWhenAbsentAndStopsAtThreeLines(): void
    {
        $withoutDescription = null;
        $withDescription = null;
        $agent = new Agent();
        $agent->setAiProvider(new FakeAIProvider());
        $terminal = new VirtualTerminal(columns: 32, rows: 30);
        $command = $this->commandThat(
            static function (Controls $controls, string $arguments): void {
                $controls->choose('First choice', [
                    new ChoiceOption('first', 'First option'),
                ]);
                $controls->choose(
                    'Second choice',
                    [new ChoiceOption('second', 'Second option')],
                    'alpha beta gamma delta epsilon zeta eta theta iota kappa lambda mu nu xi omicron pi rho sigma tau',
                );
            },
        );
        EventLoop::delay(
            0.04,
            static fn () => $terminal->simulateInput("/probe\r"),
        );
        EventLoop::delay(
            0.1,
            static function () use ($terminal): void {
                $terminal->clearOutput();
                $terminal->simulateResize(32, 30);
            },
        );
        EventLoop::delay(
            0.13,
            static function () use (&$withoutDescription, $terminal): void {
                $withoutDescription = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->simulateInput("\r");
            },
        );
        EventLoop::delay(
            0.18,
            static function () use ($terminal): void {
                $terminal->clearOutput();
                $terminal->simulateResize(32, 30);
            },
        );
        EventLoop::delay(
            0.21,
            static function () use (&$withDescription, $terminal): void {
                $withDescription = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->simulateInput("\x1b");
            },
        );
        EventLoop::delay(
            0.3,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: [$command],
        ))->run();

        self::assertIsString($withoutDescription);
        $withoutLines = explode("\n", str_replace("\r", '', $withoutDescription));
        $headingLine = array_find_key(
            $withoutLines,
            static fn (string $line): bool => str_contains($line, 'First choice'),
        );
        $optionLine = array_find_key(
            $withoutLines,
            static fn (string $line): bool => str_contains($line, 'First option'),
        );
        self::assertIsInt($headingLine);
        self::assertIsInt($optionLine);
        self::assertSame(2, $optionLine - $headingLine);

        self::assertIsString($withDescription);
        self::assertStringContainsString('alpha beta gamma delta', $withDescription);
        self::assertStringContainsString('…', $withDescription);
        self::assertStringNotContainsString('rho sigma tau', $withDescription);
    }

    public function testACommandCanOfferCompleteLabelAndDetailBlocks(): void
    {
        $chosen = null;
        $pickerOutput = null;
        $agent = new Agent();
        $agent->setAiProvider(new FakeAIProvider());
        $terminal = new VirtualTerminal(columns: 42, rows: 32);
        $command = $this->commandThat(
            static function (
                Controls $controls,
                string $arguments,
            ) use (&$chosen): void {
                $chosen = $controls->choose('Models', [
                    new ChoiceOption(
                        'detailed',
                        "A selected label with a supplied\nline break and enough text to need more than two visual lines",
                        "A lighter detail with its own\r\nline break and enough text to need more than two visual lines",
                    ),
                    new ChoiceOption('plain', 'A plain option'),
                ]);
            },
        );
        EventLoop::delay(
            0.04,
            static fn () => $terminal->simulateInput("/probe\r"),
        );
        EventLoop::delay(
            0.1,
            static function () use (&$pickerOutput, $terminal): void {
                $pickerOutput = $terminal->getOutput();
                $terminal->simulateInput("\r");
            },
        );
        EventLoop::delay(
            0.2,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: [$command],
        ))->run();

        self::assertIsString($pickerOutput);
        $display = str_replace(
            "\r",
            '',
            AnsiUtils::stripAnsiCodes($pickerOutput),
        );
        self::assertStringContainsString(
            'A selected label with a supplied line',
            $display,
        );
        self::assertStringContainsString(
            'A lighter detail with its own line',
            $display,
        );
        self::assertStringContainsString('…', $display);
        $lines = explode("\n", $display);
        $label = array_values(array_filter(
            $lines,
            static fn (string $line): bool => str_contains(
                $line,
                'A selected label',
            ),
        ))[0];
        $labelContinuation = array_values(array_filter(
            $lines,
            static fn (string $line): bool => str_contains(
                $line,
                'break and enough',
            ),
        ))[0];
        $detail = array_values(array_filter(
            $lines,
            static fn (string $line): bool => str_contains(
                $line,
                'A lighter detail',
            ),
        ))[0];
        self::assertSame(
            mb_strpos($label, 'A'),
            mb_strpos($labelContinuation, 'break'),
        );
        self::assertSame(mb_strpos($label, 'A'), mb_strpos($detail, 'A'));
        self::assertSame(
            mb_strpos($label, 'A') - 2,
            mb_strpos($label, '→'),
        );
        $labelIndex = array_search($label, $lines, true);
        $detailIndex = array_search($detail, $lines, true);
        $plainIndex = null;

        foreach ($lines as $index => $line) {
            if (str_contains($line, 'A plain option')) {
                $plainIndex = $index;
                break;
            }
        }

        self::assertIsInt($labelIndex);
        self::assertIsInt($detailIndex);
        self::assertIsInt($plainIndex);
        self::assertSame($labelIndex + 2, $detailIndex);
        self::assertSame($detailIndex + 3, $plainIndex);
        self::assertSame('', trim($lines[$plainIndex - 1]));
        self::assertStringContainsString(
            "\x1b[35mA selected label",
            $pickerOutput,
        );
        self::assertStringContainsString(
            "\x1b[90mA lighter detail",
            $pickerOutput,
        );
        self::assertSame('detailed', $chosen);
    }

    public function testPickerNavigationScrollsWholeOptionBlocks(): void
    {
        $chosen = null;
        $scrolledDisplay = null;
        $agent = new Agent();
        $agent->setAiProvider(new FakeAIProvider());
        $terminal = new VirtualTerminal(rows: 40);
        $command = $this->commandThat(
            static function (
                Controls $controls,
                string $arguments,
            ) use (&$chosen): void {
                $options = [];

                for ($number = 1; $number <= 10; ++$number) {
                    $options[] = new ChoiceOption(
                        'key-' . $number,
                        'Label ' . $number,
                        'Detail ' . $number,
                    );
                }

                $chosen = $controls->choose('Models', $options);
            },
        );
        EventLoop::delay(
            0.04,
            static fn () => $terminal->simulateInput("/probe\r"),
        );
        EventLoop::delay(
            0.1,
            static function () use ($terminal): void {
                $terminal->clearOutput();
                $terminal->simulateInput(str_repeat("\x1b[B", 8));
            },
        );
        EventLoop::delay(
            0.16,
            static function () use (&$scrolledDisplay, $terminal): void {
                $scrolledDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->simulateInput("\r");
            },
        );
        EventLoop::delay(
            0.26,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: [$command],
        ))->run();

        self::assertIsString($scrolledDisplay);
        self::assertStringContainsString('→ Label 9', $scrolledDisplay);
        self::assertStringContainsString('Detail 9', $scrolledDisplay);
        self::assertDoesNotMatchRegularExpression(
            '/Label 1(?:\s|\r?\n)/',
            $scrolledDisplay,
        );
        self::assertSame('key-9', $chosen);
    }

    public function testPickerViewportShowsFourCompleteBlocksAndWrapsWithSelection(): void
    {
        $chosen = null;
        $initialDisplay = null;
        $scrolledDisplay = null;
        $wrappedDisplay = null;
        $agent = new Agent();
        $agent->setAiProvider(new FakeAIProvider());
        $terminal = new VirtualTerminal(columns: 36, rows: 40);
        $command = $this->commandThat(
            static function (
                Controls $controls,
                string $arguments,
            ) use (&$chosen): void {
                $chosen = $controls->choose('Viewport', [
                    new ChoiceOption('one', 'Option one', 'Detail one'),
                    new ChoiceOption('two', 'Option two'),
                    new ChoiceOption(
                        'three',
                        'Option three has a label that wraps',
                        'Detail three also wraps at this width',
                    ),
                    new ChoiceOption('four', 'Option four', 'Detail four'),
                    new ChoiceOption('five', 'Option five', 'Detail five'),
                    new ChoiceOption('six', 'Option six', 'Detail six'),
                ]);
            },
        );
        EventLoop::delay(
            0.04,
            static fn () => $terminal->simulateInput("/probe\r"),
        );
        EventLoop::delay(
            0.1,
            static function () use ($terminal): void {
                $terminal->clearOutput();
                $terminal->simulateResize(36, 40);
            },
        );
        EventLoop::delay(
            0.14,
            static function () use (&$initialDisplay, $terminal): void {
                $initialDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->clearOutput();
                $terminal->simulateInput(str_repeat("\x1b[B", 4));
                $terminal->simulateResize(36, 40);
            },
        );
        EventLoop::delay(
            0.21,
            static function () use (&$scrolledDisplay, $terminal): void {
                $scrolledDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->clearOutput();
                $terminal->simulateInput(str_repeat("\x1b[A", 5));
                $terminal->simulateResize(36, 40);
            },
        );
        EventLoop::delay(
            0.28,
            static function () use (&$wrappedDisplay, $terminal): void {
                $wrappedDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->simulateInput("\r");
            },
        );
        EventLoop::delay(
            0.38,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: [$command],
        ))->run();

        self::assertIsString($initialDisplay);
        self::assertStringContainsString('→ Option one', $initialDisplay);
        self::assertStringContainsString('Detail three also', $initialDisplay);
        self::assertStringContainsString('Option four', $initialDisplay);
        self::assertStringNotContainsString('Option five', $initialDisplay);
        self::assertStringNotContainsString('Option six', $initialDisplay);

        self::assertIsString($scrolledDisplay);
        self::assertStringContainsString('Viewport (5 of 6)', $scrolledDisplay);
        self::assertStringContainsString('→ Option five', $scrolledDisplay);
        self::assertStringContainsString('Detail five', $scrolledDisplay);
        self::assertStringNotContainsString('Option one', $scrolledDisplay);

        self::assertIsString($wrappedDisplay);
        self::assertStringContainsString('Viewport (6 of 6)', $wrappedDisplay);
        self::assertStringContainsString('→ Option six', $wrappedDisplay);
        self::assertStringContainsString('Detail six', $wrappedDisplay);
        self::assertStringNotContainsString('Option one', $wrappedDisplay);
        self::assertSame('six', $chosen);
    }

    public function testPickerResizeKeepsQueryAndSelectionWhileReducingCompleteBlocks(): void
    {
        $chosen = null;
        $shortDisplay = null;
        $lowDisplay = null;
        $grownDisplay = null;
        $reopenedDisplay = null;
        $agent = new Agent();
        $agent->setAiProvider(new FakeAIProvider());
        $terminal = new VirtualTerminal(columns: 40, rows: 30);
        $command = $this->commandThat(
            static function (
                Controls $controls,
                string $arguments,
            ) use (&$chosen): void {
                $options = [
                    new ChoiceOption('one', 'Match first choice'),
                    new ChoiceOption(
                        'two',
                        'Match second choice with a long label',
                        'Second supporting detail is also long',
                    ),
                    new ChoiceOption(
                        'three',
                        'Match third choice with a long label',
                        'Third supporting detail is also long',
                    ),
                    new ChoiceOption('four', 'Match fourth choice'),
                    new ChoiceOption('five', 'Match fifth choice', 'Fifth supporting detail'),
                    new ChoiceOption('six', 'Match sixth choice'),
                ];
                $chosen = $controls->choose('Resizable', $options);
                $controls->choose('Reopened', $options);
            },
        );
        EventLoop::delay(
            0.04,
            static fn () => $terminal->simulateInput("/probe\r"),
        );
        EventLoop::delay(
            0.1,
            static function () use ($terminal): void {
                $terminal->simulateInput('match');
                $terminal->simulateInput("\x1b[B\x1b[B");
                $terminal->clearOutput();
                $terminal->simulateResize(22, 24);
            },
        );
        EventLoop::delay(
            0.17,
            static function () use (&$shortDisplay, $terminal): void {
                $shortDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->clearOutput();
                $terminal->simulateResize(22, 7);
            },
        );
        EventLoop::delay(
            0.24,
            static function () use (&$lowDisplay, $terminal): void {
                $lowDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->clearOutput();
                $terminal->simulateResize(40, 30);
            },
        );
        EventLoop::delay(
            0.31,
            static function () use (&$grownDisplay, $terminal): void {
                $grownDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->simulateInput("\r");
            },
        );
        EventLoop::delay(
            0.38,
            static function () use ($terminal): void {
                $terminal->clearOutput();
                $terminal->simulateResize(40, 30);
            },
        );
        EventLoop::delay(
            0.43,
            static function () use (&$reopenedDisplay, $terminal): void {
                $reopenedDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->simulateInput("\x1b");
            },
        );
        EventLoop::delay(
            0.51,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: [$command],
        ))->run();

        self::assertIsString($shortDisplay);
        self::assertStringContainsString('Resizable (3 of 6)', $shortDisplay);
        self::assertStringContainsString('Search: match', $shortDisplay);
        self::assertStringContainsString('→ Match third choice', $shortDisplay);
        self::assertStringContainsString('Third supporting', $shortDisplay);
        self::assertStringNotContainsString('Match second choice', $shortDisplay);
        self::assertStringNotContainsString('Match fourth choice', $shortDisplay);

        self::assertIsString($lowDisplay);
        $lowLines = explode("\n", str_replace("\r", '', $lowDisplay));
        $visibleLowDisplay = implode("\n", array_slice($lowLines, -7));
        self::assertStringContainsString(
            '→ Match third choic…',
            $visibleLowDisplay,
        );
        self::assertStringNotContainsString(
            'Third supporting',
            $visibleLowDisplay,
        );
        self::assertStringContainsString('↑↓ move · Enter', $visibleLowDisplay);
        self::assertStringContainsString('chooses · Escape', $visibleLowDisplay);
        self::assertStringContainsString('cancels', $visibleLowDisplay);

        self::assertIsString($grownDisplay);
        self::assertStringContainsString('Resizable (3 of 6)', $grownDisplay);
        self::assertStringContainsString('Search: match', $grownDisplay);
        self::assertStringContainsString('→ Match third choice', $grownDisplay);
        self::assertStringContainsString('Match sixth choice', $grownDisplay);
        self::assertStringNotContainsString('Match second choice', $grownDisplay);
        self::assertSame('three', $chosen);

        self::assertIsString($reopenedDisplay);
        self::assertStringContainsString('Reopened (1 of 6)', $reopenedDisplay);
        self::assertStringContainsString('Search: ', $reopenedDisplay);
        self::assertStringNotContainsString('Search: match', $reopenedDisplay);
        self::assertStringContainsString('→ Match first choice', $reopenedDisplay);
    }

    /**
     * Escape gives the command no choice at all, and it can tell that apart
     * from a choice made. Meanwhile the composer takes no text: what was
     * typed narrowed the list and is gone with it.
     */
    public function testACommandTellsAnAbandonedChoiceApartFromAChosenKey(): void
    {
        $chosen = 'nothing yet';
        $agent = new Agent();
        $agent->setAiProvider(new FakeAIProvider());
        $terminal = new VirtualTerminal(rows: 24);
        $command = $this->commandThat(
            static function (
                Controls $controls,
                string $arguments,
            ) use (&$chosen): void {
                $chosen = $controls->choose('Models', [
                    new ChoiceOption('haiku', 'Claude Haiku'),
                ]);
                $controls->say('Chosen: ' . ($chosen ?? 'nothing'));
            },
        );
        EventLoop::delay(
            0.04,
            static fn () => $terminal->simulateInput("/probe\r"),
        );
        EventLoop::delay(
            0.1,
            static function () use ($terminal): void {
                $terminal->clearOutput();
                $terminal->simulateInput('zzz');
            },
        );
        EventLoop::delay(
            0.16,
            static function () use ($terminal): void {
                $terminal->clearOutput();
                $terminal->simulateInput("\x1b");
            },
        );
        EventLoop::delay(
            0.26,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: [$command],
        ))->run();

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());

        self::assertNull($chosen);
        self::assertStringContainsString('Chosen: nothing', $display);
        self::assertStringNotContainsString('Claude Haiku', $display);
        self::assertStringNotContainsString('zzz', $display);
        self::assertStringContainsString('ready · Enter sends', $display);
    }

    /**
     * Leaving the terminal mid-choice answers the command with nothing, so
     * that nobody is left waiting on a list that will never come back.
     */
    public function testLeavingWhileAListIsOpenAnswersTheCommandWithNothing(): void
    {
        $chosen = 'nothing yet';
        $completions = 0;
        $agent = new Agent();
        $agent->setAiProvider(new FakeAIProvider());
        $terminal = new VirtualTerminal(rows: 24);
        $command = $this->commandThat(
            static function (
                Controls $controls,
                string $arguments,
            ) use (&$chosen, &$completions): void {
                $chosen = $controls->choose('Models', [
                    new ChoiceOption('haiku', 'Claude Haiku'),
                ]);
                ++$completions;
            },
        );
        EventLoop::delay(
            0.04,
            static fn () => $terminal->simulateInput("/probe\r"),
        );
        EventLoop::delay(
            0.1,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: [$command],
        ))->run();

        self::assertNull($chosen);
        self::assertSame(1, $completions);
    }

    public function testTypingNarrowsAListACommandOffered(): void
    {
        $chosen = 'nothing yet';
        $narrowedDisplay = null;
        $agent = new Agent();
        $agent->setAiProvider(new FakeAIProvider());
        $terminal = new VirtualTerminal(rows: 24);
        $command = $this->commandThat(
            static function (
                Controls $controls,
                string $arguments,
            ) use (&$chosen): void {
                $chosen = $controls->choose('Models', [
                    new ChoiceOption('haiku', 'Claude Haiku'),
                    new ChoiceOption('opus', 'Claude Opus'),
                    new ChoiceOption('sonnet', 'Claude Sonnet'),
                    new ChoiceOption('gemini', 'Gemini Pro'),
                    new ChoiceOption('gpt', 'GPT'),
                    new ChoiceOption('mistral', 'Mistral'),
                ]);
            },
        );
        EventLoop::delay(
            0.04,
            static fn () => $terminal->simulateInput("/probe\r"),
        );
        EventLoop::delay(
            0.1,
            static function () use ($terminal): void {
                $terminal->clearOutput();
                $terminal->simulateInput('Claude O');
            },
        );
        EventLoop::delay(
            0.16,
            static function () use (&$narrowedDisplay, $terminal): void {
                $narrowedDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->simulateInput("\r");
            },
        );
        EventLoop::delay(
            0.26,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: [$command],
        ))->run();

        self::assertIsString($narrowedDisplay);
        self::assertStringContainsString('Claude Opus', $narrowedDisplay);
        self::assertStringNotContainsString('Claude Haiku', $narrowedDisplay);
        self::assertSame('opus', $chosen);
    }

    public function testOnlyAChoiceOfAtLeastSixOptionsAcceptsVisibleSearch(): void
    {
        $shortChoice = null;
        $longChoice = null;
        $shortDisplay = null;
        $longDisplay = null;
        $filteredDisplay = null;
        $agent = new Agent();
        $agent->setAiProvider(new FakeAIProvider());
        $terminal = new VirtualTerminal(rows: 30);
        $command = $this->commandThat(
            static function (
                Controls $controls,
                string $arguments,
            ) use (&$shortChoice, &$longChoice): void {
                $shortChoice = $controls->choose('Short choice', [
                    new ChoiceOption('short-1', 'Short one'),
                    new ChoiceOption('short-2', 'Short two'),
                    new ChoiceOption('short-3', 'Short three'),
                    new ChoiceOption('short-4', 'Short four'),
                    new ChoiceOption('short-5', 'Short five'),
                ]);
                $longChoice = $controls->choose('Long choice', [
                    new ChoiceOption('long-1', 'Long one'),
                    new ChoiceOption('long-2', 'Long two'),
                    new ChoiceOption('long-3', 'Long three'),
                    new ChoiceOption('long-4', 'Long four'),
                    new ChoiceOption('long-5', 'Long five'),
                    new ChoiceOption('long-6', 'Long six'),
                ]);
            },
        );
        EventLoop::delay(
            0.04,
            static fn () => $terminal->simulateInput("/probe\r"),
        );
        EventLoop::delay(
            0.1,
            static function () use (&$shortDisplay, $terminal): void {
                $shortDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->simulateInput('Short five');
                $terminal->simulateInput("\r");
            },
        );
        EventLoop::delay(
            0.17,
            static function () use (&$longDisplay, $terminal): void {
                $longDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->clearOutput();
                $terminal->simulateInput('six');
            },
        );
        EventLoop::delay(
            0.23,
            static function () use (&$filteredDisplay, $terminal): void {
                $filteredDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->simulateInput("\r");
            },
        );
        EventLoop::delay(
            0.32,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: [$command],
        ))->run();

        self::assertIsString($shortDisplay);
        self::assertStringNotContainsString('Search:', $shortDisplay);
        self::assertSame('short-1', $shortChoice);
        self::assertIsString($longDisplay);
        self::assertStringContainsString('Search:', $longDisplay);
        self::assertIsString($filteredDisplay);
        self::assertStringContainsString('Search: six', $filteredDisplay);
        self::assertStringContainsString('Long choice (1 of 1)', $filteredDisplay);
        self::assertStringContainsString('Long six', $filteredDisplay);
        self::assertSame('long-6', $longChoice);
    }

    public function testSearchUsesCompleteLabelsAndDetailsWithoutReordering(): void
    {
        $chosen = null;
        $filteredDisplay = null;
        $agent = new Agent();
        $agent->setAiProvider(new FakeAIProvider());
        $terminal = new VirtualTerminal(columns: 38, rows: 32);
        $command = $this->commandThat(
            static function (
                Controls $controls,
                string $arguments,
            ) use (&$chosen): void {
                $chosen = $controls->choose('Models', [
                    new ChoiceOption('alpha', 'Alpha'),
                    new ChoiceOption(
                        'detail',
                        'First matching option',
                        'A detail whose visible beginning is deliberately long before HIDDEN NEEDLE',
                    ),
                    new ChoiceOption('label', 'Middle nEeDlE label'),
                    new ChoiceOption('later', 'Later option', 'needle detail'),
                    new ChoiceOption('fifth', 'Fifth option'),
                    new ChoiceOption('sixth', 'Sixth option'),
                ]);
            },
        );
        EventLoop::delay(
            0.04,
            static fn () => $terminal->simulateInput("/probe\r"),
        );
        EventLoop::delay(
            0.1,
            static function () use ($terminal): void {
                $terminal->simulateInput("\x1b[B\x1b[B");
                $terminal->clearOutput();
                $terminal->simulateInput('NeEdLe');
            },
        );
        EventLoop::delay(
            0.17,
            static fn () => $terminal->simulateResize(38, 32),
        );
        EventLoop::delay(
            0.2,
            static function () use (&$filteredDisplay, $terminal): void {
                $filteredDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->simulateInput("\r");
            },
        );
        EventLoop::delay(
            0.3,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: [$command],
        ))->run();

        self::assertIsString($filteredDisplay);
        self::assertStringContainsString('Models (1 of 3)', $filteredDisplay);
        self::assertStringContainsString('→ First matching option', $filteredDisplay);
        self::assertLessThan(
            mb_strpos($filteredDisplay, 'Middle nEeDlE label'),
            mb_strpos($filteredDisplay, 'First matching option'),
        );
        self::assertLessThan(
            mb_strpos($filteredDisplay, 'Later option'),
            mb_strpos($filteredDisplay, 'Middle nEeDlE label'),
        );
        self::assertSame('detail', $chosen);
    }

    public function testEmptySearchIsInertAndEveryOpeningStartsFresh(): void
    {
        $first = 'still open';
        $second = 'still open';
        $emptyDisplay = null;
        $afterEnter = null;
        $restoredDisplay = null;
        $reopenedDisplay = null;
        $agent = new Agent();
        $agent->setAiProvider(new FakeAIProvider());
        $terminal = new VirtualTerminal(rows: 30);
        $command = $this->commandThat(
            static function (
                Controls $controls,
                string $arguments,
            ) use (&$first, &$second): void {
                $options = [
                    new ChoiceOption('one', 'Option one'),
                    new ChoiceOption('two', 'Option two'),
                    new ChoiceOption('three', 'Option three'),
                    new ChoiceOption('four', 'Option four'),
                    new ChoiceOption('five', 'Option five'),
                    new ChoiceOption('six', 'Option six'),
                ];
                $first = $controls->choose('First opening', $options);
                $second = $controls->choose('Second opening', $options);
            },
        );
        EventLoop::delay(
            0.04,
            static fn () => $terminal->simulateInput("/probe\r"),
        );
        EventLoop::delay(
            0.1,
            static function () use ($terminal): void {
                $terminal->clearOutput();
                $terminal->simulateInput('zzz');
            },
        );
        EventLoop::delay(
            0.16,
            static function () use (&$emptyDisplay, $terminal): void {
                $emptyDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->clearOutput();
                $terminal->simulateInput("\r");
            },
        );
        EventLoop::delay(
            0.2,
            static fn () => $terminal->simulateResize(80, 30),
        );
        EventLoop::delay(
            0.23,
            static function () use (&$afterEnter, $terminal): void {
                $afterEnter = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->clearOutput();
                $terminal->simulateInput("\x7f");
                $terminal->simulateInput("\x7f");
                $terminal->simulateInput("\x7f");
            },
        );
        EventLoop::delay(
            0.3,
            static function () use (&$restoredDisplay, $terminal): void {
                $restoredDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->simulateInput("\x1b[B");
                $terminal->simulateInput("\x1b");
            },
        );
        EventLoop::delay(
            0.37,
            static function () use (&$reopenedDisplay, $terminal): void {
                $reopenedDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->simulateInput("\x1b");
            },
        );
        EventLoop::delay(
            0.46,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: [$command],
        ))->run();

        self::assertIsString($emptyDisplay);
        self::assertStringContainsString('First opening (0 of 0)', $emptyDisplay);
        self::assertStringContainsString('Search: zzz', $emptyDisplay);
        self::assertStringContainsString('No options match "zzz"', $emptyDisplay);
        self::assertIsString($afterEnter);
        self::assertStringContainsString('No options match "zzz"', $afterEnter);
        self::assertIsString($restoredDisplay);
        self::assertStringContainsString('First opening (1 of 6)', $restoredDisplay);
        self::assertStringContainsString('Search:', $restoredDisplay);
        self::assertStringContainsString('→ Option one', $restoredDisplay);
        self::assertNull($first);
        self::assertIsString($reopenedDisplay);
        self::assertStringContainsString('Second opening (1 of 6)', $reopenedDisplay);
        self::assertStringNotContainsString('Search: z', $reopenedDisplay);
        self::assertStringContainsString('→ Option one', $reopenedDisplay);
        self::assertNull($second);
    }

    /**
     * Makes the next render paint the whole screen rather than the lines
     * the last one touched.
     *
     * A resize at an unchanged size is a full repaint, which is what makes
     * the absence of a line mean it is not on screen.
     */
    private static function forceRepaint(VirtualTerminal $terminal): void
    {
        $terminal->clearOutput();
        $terminal->simulateResize(80, 30);
    }

    /**
     * The Command suggestions: what is mounted, while a name is written.
     */
    public function testWritingASlashShowsTheMountedCommandsWithTheirDescriptions(): void
    {
        $provider = new FakeAIProvider();
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $terminal = new VirtualTerminal(rows: 30);
        $display = null;
        EventLoop::delay(
            0.05,
            static fn () => $terminal->simulateInput('/'),
        );
        EventLoop::delay(
            0.1,
            static fn () => self::forceRepaint($terminal),
        );
        EventLoop::delay(
            0.15,
            static function () use (&$display, $terminal): void {
                $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                $terminal->simulateInput("\x03");
            },
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: [new Help(), new Leave()],
        ))->run();

        self::assertIsString($display);
        self::assertStringContainsString('/help', $display);
        self::assertStringContainsString(
            'Lists what can be typed here.',
            $display,
        );
        self::assertStringContainsString('/exit', $display);
        self::assertStringContainsString(
            'Closes the Conversation TUI.',
            $display,
        );
        // The Host Application named /help first, and reads it first.
        self::assertLessThan(
            strpos($display, '/exit'),
            strpos($display, '/help'),
        );
        $provider->assertNothingSent();
    }

    /**
     * Nothing is suspended: the keys are still the composer's.
     */
    public function testTheSuggestionsSitAboveAComposerThatKeepsTakingText(): void
    {
        $agent = new Agent();
        $terminal = new VirtualTerminal(rows: 30);
        $display = null;
        EventLoop::delay(
            0.05,
            static fn () => $terminal->simulateInput('/hel'),
        );
        EventLoop::delay(
            0.1,
            static fn () => self::forceRepaint($terminal),
        );
        EventLoop::delay(
            0.15,
            static function () use (&$display, $terminal): void {
                $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                $terminal->simulateInput("\x03");
            },
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: [new Help()],
        ))->run();

        self::assertIsString($display);
        $lines = preg_split('/\r\n|\r|\n/', $display);
        self::assertIsArray($lines);
        $suggestion = null;
        $composer = null;

        foreach ($lines as $index => $line) {
            if (
                $suggestion === null
                && str_contains($line, 'Lists what can be typed here.')
            ) {
                $suggestion = $index;

                continue;
            }

            if ($suggestion !== null && str_starts_with(trim($line), '❯')) {
                $composer = $index;
                break;
            }
        }

        self::assertNotNull($suggestion);
        self::assertNotNull($composer);
        self::assertStringContainsString('/hel', $lines[$composer]);
    }

    /**
     * More commands than fit are scrolled to, not silently dropped.
     */
    public function testMoreCommandsThanFitAreCountedRatherThanDropped(): void
    {
        $agent = new Agent();
        $terminal = new VirtualTerminal(rows: 30);
        $commands = [];

        for ($place = 0; $place < 10; ++$place) {
            $commands[] = $this->commandThat(
                static function (Controls $controls, string $arguments): void {
                },
                '/cmd' . $place,
            );
        }

        $display = null;
        EventLoop::delay(
            0.05,
            static fn () => $terminal->simulateInput('/'),
        );
        EventLoop::delay(
            0.1,
            static fn () => self::forceRepaint($terminal),
        );
        EventLoop::delay(
            0.15,
            static function () use (&$display, $terminal): void {
                $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                $terminal->simulateInput("\x03");
            },
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: $commands,
        ))->run();

        self::assertIsString($display);
        self::assertStringContainsString('/cmd0', $display);
        self::assertStringContainsString('/cmd7', $display);
        // Eight lines are visible, and the counter says how many there are.
        self::assertStringNotContainsString('/cmd8', $display);
        self::assertStringContainsString('(1/10)', $display);
    }

    /**
     * A draft that is no longer a name takes the suggestions away.
     */
    public function testASpaceANewLineOrADeletedSlashTakesTheSuggestionsAway(): void
    {
        $agent = new Agent();
        $terminal = new VirtualTerminal(rows: 30);
        $open = null;
        $afterSpace = null;
        $afterNewLine = null;
        $afterBackspace = null;
        EventLoop::delay(
            0.05,
            static fn () => $terminal->simulateInput('/'),
        );
        EventLoop::delay(
            0.1,
            static fn () => self::forceRepaint($terminal),
        );
        EventLoop::delay(
            0.15,
            static function () use (&$open, $terminal): void {
                $open = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                $terminal->simulateInput(' ');
            },
        );
        EventLoop::delay(
            0.2,
            static fn () => self::forceRepaint($terminal),
        );
        EventLoop::delay(
            0.25,
            static function () use (&$afterSpace, $terminal): void {
                $afterSpace = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                // Escape empties the composer, and a new name is written.
                $terminal->simulateInput("\x1b");
                $terminal->simulateInput('/');
            },
        );
        EventLoop::delay(
            0.3,
            static function () use ($terminal): void {
                $terminal->simulateInput("\x1b[13;2u");
                self::forceRepaint($terminal);
            },
        );
        EventLoop::delay(
            0.35,
            static function () use (&$afterNewLine, $terminal): void {
                $afterNewLine = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->simulateInput("\x1b");
                $terminal->simulateInput('/');
            },
        );
        EventLoop::delay(
            0.4,
            static function () use ($terminal): void {
                $terminal->simulateInput("\x7f");
                self::forceRepaint($terminal);
            },
        );
        EventLoop::delay(
            0.45,
            static function () use (&$afterBackspace, $terminal): void {
                $afterBackspace = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->simulateInput("\x03");
            },
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: [new Help()],
        ))->run();

        self::assertIsString($open);
        self::assertStringContainsString(
            'Lists what can be typed here.',
            $open,
        );

        foreach ([$afterSpace, $afterNewLine, $afterBackspace] as $display) {
            self::assertIsString($display);
            self::assertStringNotContainsString(
                'Lists what can be typed here.',
                $display,
            );
        }
    }

    /**
     * A slash in the middle of a message is text for the Agent.
     */
    public function testASlashInTheMiddleOfAMessageSuggestsNothing(): void
    {
        $agent = new Agent();
        $terminal = new VirtualTerminal(rows: 30);
        $display = null;
        EventLoop::delay(
            0.05,
            static fn () => $terminal->simulateInput('ask /help about it'),
        );
        EventLoop::delay(
            0.1,
            static fn () => self::forceRepaint($terminal),
        );
        EventLoop::delay(
            0.15,
            static function () use (&$display, $terminal): void {
                $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                $terminal->simulateInput("\x03");
            },
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: [new Help()],
        ))->run();

        self::assertIsString($display);
        self::assertStringNotContainsString(
            'Lists what can be typed here.',
            $display,
        );
    }

    /**
     * A terminal with nothing mounted says so rather than showing nothing.
     */
    public function testATerminalWithoutCommandsSaysNothingMatches(): void
    {
        $agent = new Agent();
        $terminal = new VirtualTerminal(rows: 30);
        $display = null;
        EventLoop::delay(
            0.05,
            static fn () => $terminal->simulateInput('/'),
        );
        EventLoop::delay(
            0.1,
            static fn () => self::forceRepaint($terminal),
        );
        EventLoop::delay(
            0.15,
            static function () use (&$display, $terminal): void {
                $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                $terminal->simulateInput("\x03");
            },
        );

        (new NeuronCli($agent, terminal: $terminal))->run();

        self::assertIsString($display);
        self::assertStringContainsString('No commands match "/"', $display);
    }

    /**
     * Enter is never intercepted: it sends what is written.
     */
    public function testEnterStillSendsWhileTheSuggestionsAreOpen(): void
    {
        $provider = new FakeAIProvider();
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $terminal = new VirtualTerminal(rows: 30);
        $sent = null;
        EventLoop::delay(
            0.05,
            static fn () => $terminal->simulateInput('/help'),
        );
        EventLoop::delay(
            0.1,
            static fn () => $terminal->simulateInput("\r"),
        );
        EventLoop::delay(
            0.15,
            static fn () => self::forceRepaint($terminal),
        );
        EventLoop::delay(
            0.2,
            static function () use (&$sent, $terminal): void {
                $sent = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                $terminal->simulateInput("\x03");
            },
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: [new Help()],
        ))->run();

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());

        self::assertStringContainsString(
            '/help — Lists what can be typed here.',
            $display,
        );
        // The command was taken and the composer emptied, so the band that
        // was open while the name was written is gone with the draft.
        self::assertIsString($sent);
        self::assertStringNotContainsString(
            ' → /help',
            $sent,
        );
        self::assertStringNotContainsString('Unknown Slash command', $display);
        $provider->assertNothingSent();
    }

    /**
     * The list keeps the names that carry what has been written.
     */
    public function testTheSuggestionsNarrowToWhatIsBeingWritten(): void
    {
        $display = AnsiUtils::stripAnsiCodes(self::screenAfterTyping(
            [new Help(), new Leave()],
            '/hel',
        ));

        self::assertStringContainsString('/help', $display);
        self::assertStringNotContainsString('/exit', $display);
    }

    /**
     * What was narrowed by writing widens again by deleting.
     */
    public function testDeletingWidensTheSuggestionsAgain(): void
    {
        $display = AnsiUtils::stripAnsiCodes(self::screenAfterTyping(
            [new Help(), new Leave()],
            '/hel',
            "\x7f\x7f\x7f",
        ));

        self::assertStringContainsString('/help', $display);
        self::assertStringContainsString('/exit', $display);
    }

    /**
     * Upper and lower case are the same letters here.
     */
    public function testTheMatchIgnoresTheCaseOfWhatIsWritten(): void
    {
        $display = AnsiUtils::stripAnsiCodes(self::screenAfterTyping(
            [new Help()],
            '/HEL',
        ));

        self::assertStringContainsString(
            'Lists what can be typed here.',
            $display,
        );
    }

    /**
     * A stretch anywhere in the name finds the command; letters that are
     * not next to each other find nothing.
     */
    public function testAStretchInTheMiddleOfANameFindsTheCommand(): void
    {
        $found = AnsiUtils::stripAnsiCodes(self::screenAfterTyping(
            [self::commandNamed('/rewind', 'Goes back a message.')],
            '/wind',
        ));
        $scattered = AnsiUtils::stripAnsiCodes(self::screenAfterTyping(
            [self::commandNamed('/rewind', 'Goes back a message.')],
            '/rwd',
        ));

        self::assertStringContainsString('Goes back a message.', $found);
        self::assertStringNotContainsString(
            'Goes back a message.',
            $scattered,
        );
        self::assertStringContainsString(
            'No commands match "/rwd"',
            $scattered,
        );
    }

    /**
     * The search is among the names, not among the meanings.
     */
    public function testADescriptionNeverBringsACommandIn(): void
    {
        $display = AnsiUtils::stripAnsiCodes(self::screenAfterTyping(
            [self::commandNamed('/alpha', 'Rewinds the conversation.')],
            '/rewind',
        ));

        self::assertStringNotContainsString('/alpha', $display);
        self::assertStringContainsString(
            'No commands match "/rewind"',
            $display,
        );
    }

    /**
     * The name written in full is the first line, whatever was mounted
     * before it.
     */
    public function testTheWholeNameIsTheFirstLine(): void
    {
        $display = AnsiUtils::stripAnsiCodes(self::screenAfterTyping(
            [
                self::commandNamed('/sessions', 'Lists the Sessions.'),
                self::commandNamed('/ses', 'The shorter one.'),
            ],
            '/ses',
        ));

        self::assertLessThan(
            strpos($display, 'Lists the Sessions.'),
            strpos($display, 'The shorter one.'),
        );
    }

    /**
     * A name that begins with what was written comes before one that merely
     * carries it.
     */
    public function testANameThatBeginsWithWhatIsWrittenComesFirst(): void
    {
        $display = AnsiUtils::stripAnsiCodes(self::screenAfterTyping(
            [
                self::commandNamed('/rewind', 'Goes back a message.'),
                self::commandNamed('/window', 'Frames the conversation.'),
            ],
            '/wind',
        ));

        self::assertLessThan(
            strpos($display, 'Goes back a message.'),
            strpos($display, 'Frames the conversation.'),
        );
    }

    /**
     * Two names that match alike are read in the order they were mounted.
     */
    public function testCommandsMatchingAlikeKeepTheirMountingOrder(): void
    {
        $display = AnsiUtils::stripAnsiCodes(self::screenAfterTyping(
            [
                self::commandNamed('/window', 'Mounted first.'),
                self::commandNamed('/winter', 'Mounted second.'),
            ],
            '/win',
        ));

        self::assertLessThan(
            strpos($display, 'Mounted second.'),
            strpos($display, 'Mounted first.'),
        );
    }

    /**
     * The three sets are read one after the other: the name written in
     * full, the names that begin with it, the names that merely carry it.
     */
    public function testTheThreeSetsAreReadOneAfterTheOther(): void
    {
        $display = AnsiUtils::stripAnsiCodes(self::screenAfterTyping(
            [
                self::commandNamed('/browses', 'Merely carries it.'),
                self::commandNamed('/sessions', 'Begins with it.'),
                self::commandNamed('/ses', 'Written in full.'),
            ],
            '/ses',
        ));

        self::assertLessThan(
            strpos($display, 'Begins with it.'),
            strpos($display, 'Written in full.'),
        );
        self::assertLessThan(
            strpos($display, 'Merely carries it.'),
            strpos($display, 'Begins with it.'),
        );
    }

    /**
     * A name too long to be read whole is still matched whole.
     */
    public function testANameLongerThanItsLineIsMatchedAllTheSame(): void
    {
        $display = AnsiUtils::stripAnsiCodes(self::screenAfterTyping(
            [self::commandNamed(
                '/remember-everything-said-in-this-conversation',
                'Keeps the long name.',
            )],
            '/conversation',
        ));

        self::assertStringContainsString('Keeps the long name.', $display);
    }

    /**
     * The stretch that matched is bold inside the name, so a line says why
     * it is there.
     */
    public function testTheMatchingStretchIsBoldInsideTheName(): void
    {
        $output = self::screenAfterTyping(
            [self::commandNamed('/sessions', 'Lists the Sessions.')],
            '/ses',
        );

        self::assertStringContainsString(
            "/\x1b[1mses\x1b[22msions",
            $output,
        );
    }

    /**
     * The screen, escape codes and all, once the given keys have been typed
     * into a TUI with the given commands mounted.
     *
     * Each string is typed a moment after the one before it, so a test can
     * write a name and then delete part of it.
     *
     * @param list<SlashCommand|RunsWhileWorking> $commands
     */
    private static function screenAfterTyping(
        array $commands,
        string ...$typed,
    ): string {
        $terminal = new VirtualTerminal(rows: 30);
        $output = null;
        $moment = 0.05;

        foreach ($typed as $keys) {
            EventLoop::delay(
                $moment,
                static fn () => $terminal->simulateInput($keys),
            );
            $moment += 0.05;
        }

        EventLoop::delay(
            $moment,
            static fn () => self::forceRepaint($terminal),
        );
        EventLoop::delay(
            $moment + 0.05,
            static function () use (&$output, $terminal): void {
                $output = $terminal->getOutput();
                $terminal->simulateInput("\x03");
            },
        );

        (new NeuronCli(
            new Agent(),
            terminal: $terminal,
            commands: $commands,
        ))->run();

        self::assertIsString($output);

        return $output;
    }

    /**
     * A command that does nothing, under a name and a description of the
     * test's choosing.
     */
    private static function commandNamed(
        string $name,
        string $description,
    ): SlashCommand {
        return new class($name, $description) implements SlashCommand {
            public function __construct(
                private readonly string $commandName,
                private readonly string $description,
            ) {
            }

            public function name(): string
            {
                return $this->commandName;
            }

            public function describe(): string
            {
                return $this->description;
            }

            public function run(
                Controls $controls,
                string $arguments,
            ): void {
            }
        };
    }

    /**
     * While the Agent works the list carries what will actually run: a
     * command the TUI would turn away is never offered there, and the whole
     * list is back under the same name once the turn has finished.
     */
    public function testTheListIsNarrowedToWhatRunsForAsLongAsTheTurnLasts(): void
    {
        $midTurnDisplay = null;
        $readyDisplay = null;
        $provider = new class(
            new AssistantMessage('A slow answer.'),
        ) extends FakeAIProvider {
            protected function streamChunks(Message $response): Generator
            {
                \Amp\delay(0.5);
                yield new TextChunk('slow-stream', 'A slow answer.');

                return $response;
            }
        };
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $terminal = new VirtualTerminal(rows: 30);
        $whileWorking = $this->commandThatRunsWhileWorking(
            static function (
                LimitedControls $controls,
                string $arguments,
            ): void {
            },
            '/pulse',
        );
        $refused = $this->commandThat(
            static function (Controls $controls, string $arguments): void {
            },
            '/probe',
        );
        EventLoop::delay(
            0.03,
            static fn () => $terminal->simulateInput("A question\r"),
        );
        EventLoop::delay(
            0.1,
            static fn () => $terminal->simulateInput('/'),
        );
        EventLoop::delay(
            0.15,
            static fn () => self::forceRepaint($terminal),
        );
        EventLoop::delay(
            0.2,
            static function () use (&$midTurnDisplay, $terminal): void {
                $midTurnDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
            },
        );
        // The answer is in by now, and the name is still being written.
        EventLoop::delay(
            0.8,
            static fn () => self::forceRepaint($terminal),
        );
        EventLoop::delay(
            0.85,
            static function () use (&$readyDisplay, $terminal): void {
                $readyDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->simulateInput("\x03");
            },
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: [$refused, $whileWorking],
        ))->run();

        self::assertIsString($midTurnDisplay);
        self::assertStringContainsString('/pulse', $midTurnDisplay);
        self::assertStringNotContainsString('/probe', $midTurnDisplay);
        self::assertIsString($readyDisplay);
        self::assertStringContainsString('/pulse', $readyDisplay);
        self::assertStringContainsString('/probe', $readyDisplay);
    }

    /**
     * Nothing runs mid-turn, so mid-turn there is nothing to offer, and the
     * band says so rather than promising a name that would be refused.
     */
    public function testWhileTheAgentWorksNothingMatchesWithoutACommandThatRunsThen(): void
    {
        $midTurnDisplay = null;
        $provider = new class(
            new AssistantMessage('A slow answer.'),
        ) extends FakeAIProvider {
            protected function streamChunks(Message $response): Generator
            {
                \Amp\delay(0.5);
                yield new TextChunk('slow-stream', 'A slow answer.');

                return $response;
            }
        };
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $terminal = new VirtualTerminal(rows: 30);
        $refused = $this->commandThat(
            static function (Controls $controls, string $arguments): void {
            },
            '/probe',
        );
        EventLoop::delay(
            0.03,
            static fn () => $terminal->simulateInput("A question\r"),
        );
        EventLoop::delay(
            0.1,
            static fn () => $terminal->simulateInput('/'),
        );
        EventLoop::delay(
            0.15,
            static fn () => self::forceRepaint($terminal),
        );
        EventLoop::delay(
            0.2,
            static function () use (&$midTurnDisplay, $terminal): void {
                $midTurnDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
            },
        );
        EventLoop::delay(
            0.9,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: [$refused],
        ))->run();

        self::assertIsString($midTurnDisplay);
        self::assertStringContainsString(
            'No commands match "/"',
            $midTurnDisplay,
        );
        self::assertStringNotContainsString('/probe', $midTurnDisplay);
    }

    /**
     * There is one thing to look at: while a person chooses from a list, the
     * composer takes no text, so there is no name being written and nothing to suggest.
     */
    public function testNoSuggestionsAreShownWhileAPickerIsOpen(): void
    {
        $choosingDisplay = null;
        $agent = new Agent();
        $agent->setAiProvider(new FakeAIProvider());
        $terminal = new VirtualTerminal(rows: 30);
        $command = $this->commandThat(
            static function (Controls $controls, string $arguments): void {
                $controls->choose('Models', [
                    new ChoiceOption('haiku', 'Claude Haiku'),
                ]);
            },
        );
        EventLoop::delay(
            0.04,
            static fn () => $terminal->simulateInput("/probe\r"),
        );
        EventLoop::delay(
            0.1,
            static fn () => $terminal->simulateInput('/'),
        );
        EventLoop::delay(
            0.15,
            static fn () => self::forceRepaint($terminal),
        );
        EventLoop::delay(
            0.2,
            static function () use (&$choosingDisplay, $terminal): void {
                $choosingDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->simulateInput("\x03");
            },
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: [$command],
        ))->run();

        self::assertIsString($choosingDisplay);
        // The picker is what is on screen, and the slash went to it.
        self::assertStringContainsString('Models', $choosingDisplay);
        self::assertStringNotContainsString(
            'Does what the test says.',
            $choosingDisplay,
        );
        self::assertStringNotContainsString(
            'No commands match',
            $choosingDisplay,
        );
    }

    /**
     * ↑↓ choose a line while the list is open, and the composer keeps its
     * cursor: the draft there is one line, so the arrows cost it nothing.
     */
    public function testTheArrowsChooseALineWithoutMovingTheCursor(): void
    {
        $display = AnsiUtils::stripAnsiCodes(self::screenAfterTyping(
            [
                self::commandNamed('/alpha', 'The first line.'),
                self::commandNamed('/album', 'The second line.'),
            ],
            '/al',
            "\x1b[A",
            'p',
        ));

        // The name written is what the arrow left alone: had the cursor
        // moved to the start of the line, the draft would read `p/al`.
        self::assertStringContainsString('/alp', $display);
        self::assertStringNotContainsString('p/al', $display);
    }

    /**
     * The line under the arrow is the one that was moved to.
     */
    public function testTheArrowsMoveTheChosenLine(): void
    {
        $display = AnsiUtils::stripAnsiCodes(self::screenAfterTyping(
            [
                self::commandNamed('/alpha', 'The first line.'),
                self::commandNamed('/album', 'The second line.'),
            ],
            '/al',
            "\x1b[B",
        ));

        self::assertStringContainsString('→ /album', $display);
        self::assertStringNotContainsString('→ /alpha', $display);
    }

    /**
     * Whoever is writing is narrowing, not scrolling: a different set of
     * lines is read from the top.
     */
    public function testTheChosenLineGoesBackToTheTopWhenTheLinesChange(): void
    {
        $display = AnsiUtils::stripAnsiCodes(self::screenAfterTyping(
            [
                self::commandNamed('/alpha', 'The first line.'),
                self::commandNamed('/album', 'The second line.'),
                self::commandNamed('/base', 'The third line.'),
            ],
            '/a',
            "\x1b[B",
            "\x1b[B",
            'l',
        ));

        self::assertStringContainsString('→ /alpha', $display);
        self::assertStringNotContainsString('→ /album', $display);
        self::assertStringNotContainsString('→ /base', $display);
    }

    /**
     * Tab writes the chosen name and a space: the list closes by its own
     * rule and the cursor is where the arguments are written.
     */
    public function testTabWritesTheChosenNameAndTheArgumentsFollowIt(): void
    {
        $agent = new Agent();
        $terminal = new VirtualTerminal(rows: 30);
        $ran = null;
        $arguments = null;
        $note = static function (string $name) use (&$ran, &$arguments) {
            return static function (
                Controls $controls,
                string $written,
            ) use ($name, &$ran, &$arguments): void {
                $ran = $name;
                $arguments = $written;
            };
        };
        $completed = null;
        EventLoop::delay(
            0.05,
            static fn () => $terminal->simulateInput('/al'),
        );
        EventLoop::delay(
            0.1,
            static fn () => $terminal->simulateInput("\x1b[B"),
        );
        EventLoop::delay(
            0.15,
            static fn () => $terminal->simulateInput("\t"),
        );
        EventLoop::delay(
            0.2,
            static fn () => self::forceRepaint($terminal),
        );
        EventLoop::delay(
            0.25,
            static function () use (&$completed, $terminal): void {
                $completed = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                $terminal->simulateInput("now\r");
            },
        );
        EventLoop::delay(
            0.3,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: [
                $this->commandThat($note('/alpha'), '/alpha'),
                $this->commandThat($note('/album'), '/album'),
            ],
        ))->run();

        self::assertIsString($completed);
        // The name that was chosen, written whole, with the list gone.
        self::assertStringContainsString('/album', $completed);
        self::assertStringNotContainsString(
            'Does what the test says.',
            $completed,
        );
        self::assertSame('/album', $ran);
        self::assertSame('now', $arguments);
    }

    /**
     * There is nothing to complete on the line that says nothing matches,
     * and Tab never writes a tabulation into the draft.
     */
    public function testTabWritesNothingWhenNoCommandMatches(): void
    {
        $agent = new Agent();
        $terminal = new VirtualTerminal(rows: 30);
        EventLoop::delay(
            0.05,
            static fn () => $terminal->simulateInput('/zz'),
        );
        EventLoop::delay(
            0.1,
            static fn () => $terminal->simulateInput("\t"),
        );
        EventLoop::delay(
            0.15,
            static fn () => $terminal->simulateInput("\r"),
        );
        EventLoop::delay(
            0.3,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: [self::commandNamed('/alpha', 'The only one.')],
        ))->run();

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());

        // What was sent is what was written, and nothing was written: `/zz`.
        self::assertStringContainsString(
            'Unknown Slash command: /zz',
            $display,
        );
        self::assertStringNotContainsString("\t", $display);
    }

    /**
     * With the list closed there is nothing to complete either, and the
     * draft is left as it was written.
     */
    public function testTabWritesNothingWhileTheListIsClosed(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('An answer.'));
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $terminal = new VirtualTerminal(rows: 30);
        EventLoop::delay(
            0.05,
            static fn () => $terminal->simulateInput('hello'),
        );
        EventLoop::delay(
            0.1,
            static fn () => $terminal->simulateInput("\t"),
        );
        EventLoop::delay(
            0.15,
            static fn () => $terminal->simulateInput("\r"),
        );
        EventLoop::delay(
            0.3,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli($agent, terminal: $terminal))->run();

        $provider->assertSent(
            static fn (RequestRecord $request): bool
                => (string) $request->messages[0]->getContent() === 'hello',
        );
    }

    /**
     * Escape takes the list away and leaves the draft; the next one empties
     * the draft, as it always has.
     */
    public function testEscapeClosesTheListAndTheNextOneEmptiesTheDraft(): void
    {
        $agent = new Agent();
        $terminal = new VirtualTerminal(rows: 30);
        $closed = null;
        $emptied = null;
        EventLoop::delay(
            0.05,
            static fn () => $terminal->simulateInput('/hel'),
        );
        EventLoop::delay(
            0.1,
            static fn () => $terminal->simulateInput("\x1b"),
        );
        EventLoop::delay(
            0.15,
            static fn () => self::forceRepaint($terminal),
        );
        EventLoop::delay(
            0.2,
            static function () use (&$closed, $terminal): void {
                $closed = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                $terminal->simulateInput("\x1b");
            },
        );
        EventLoop::delay(
            0.25,
            static fn () => self::forceRepaint($terminal),
        );
        EventLoop::delay(
            0.3,
            static function () use (&$emptied, $terminal): void {
                $emptied = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                $terminal->simulateInput("\x03");
            },
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: [new Help()],
        ))->run();

        self::assertIsString($closed);
        self::assertStringNotContainsString(
            'Lists what can be typed here.',
            $closed,
        );
        self::assertStringContainsString('/hel', $closed);
        self::assertIsString($emptied);
        self::assertStringNotContainsString('/hel', $emptied);
    }

    /**
     * The line that says nothing matches covers the conversation as the list
     * does, so Escape takes it away too, and the draft stays to be corrected.
     */
    public function testEscapeAlsoTakesAwayTheLineThatSaysNothingMatches(): void
    {
        $display = AnsiUtils::stripAnsiCodes(self::screenAfterTyping(
            [self::commandNamed('/alpha', 'The only one.')],
            '/zz',
            "\x1b",
        ));

        self::assertStringNotContainsString('No commands match', $display);
        self::assertStringContainsString('/zz', $display);
    }

    /**
     * A list longer than the band is scrolled through rather than cut: the
     * last name is reached with the arrows, and the counter says where one is.
     */
    public function testTheArrowsReachTheLastOfMoreCommandsThanFit(): void
    {
        $commands = [];

        for ($place = 0; $place < 10; ++$place) {
            $commands[] = self::commandNamed(
                '/cmd' . $place,
                'Mounted ' . $place . '.',
            );
        }

        $display = AnsiUtils::stripAnsiCodes(self::screenAfterTyping(
            $commands,
            '/',
            // Upwards from the first line is the last of them.
            "\x1b[A",
        ));

        self::assertStringContainsString('→ /cmd9', $display);
        self::assertStringContainsString('(10/10)', $display);
    }

    /**
     * The status line names the keys that mean something while the list is
     * open, and goes back to what it said as soon as it closes.
     */
    public function testTheStatusLineNamesTheKeysWhileTheListIsOpen(): void
    {
        $agent = new Agent();
        $terminal = new VirtualTerminal(rows: 30);
        $open = null;
        $closed = null;
        EventLoop::delay(
            0.05,
            static fn () => $terminal->simulateInput('/'),
        );
        EventLoop::delay(
            0.1,
            static fn () => self::forceRepaint($terminal),
        );
        EventLoop::delay(
            0.15,
            static function () use (&$open, $terminal): void {
                $open = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                $terminal->simulateInput(' ');
            },
        );
        EventLoop::delay(
            0.2,
            static fn () => self::forceRepaint($terminal),
        );
        EventLoop::delay(
            0.25,
            static function () use (&$closed, $terminal): void {
                $closed = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                $terminal->simulateInput("\x03");
            },
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: [new Help()],
        ))->run();

        self::assertIsString($open);
        self::assertStringContainsString('↑↓ moves', $open);
        self::assertStringContainsString('Tab completes', $open);
        self::assertStringNotContainsString('ready · Enter sends', $open);
        self::assertIsString($closed);
        self::assertStringContainsString('ready · Enter sends', $closed);
        self::assertStringNotContainsString('Tab completes', $closed);
    }

    /**
     * A command that does what the test tells it to, under a name of the
     * test's choosing.
     *
     * @param Closure(Controls, string): void $run
     */
    private function commandThat(
        Closure $run,
        string $name = '/probe',
    ): SlashCommand {
        return new class($run, $name) implements SlashCommand {
            /**
             * @param Closure(Controls, string): void $run
             */
            public function __construct(
                private readonly Closure $run,
                private readonly string $commandName,
            ) {
            }

            public function name(): string
            {
                return $this->commandName;
            }

            public function describe(): string
            {
                return 'Does what the test says.';
            }

            public function run(Controls $controls, string $arguments): void
            {
                ($this->run)($controls, $arguments);
            }
        };
    }

    /**
     * A command that says it may run while the Agent is working, and does
     * what the test tells it to with the fewer Controls it is handed.
     *
     * @param Closure(LimitedControls, string): void $run
     */
    private function commandThatRunsWhileWorking(
        Closure $run,
        string $name = '/probe',
    ): RunsWhileWorking {
        return new class($run, $name) implements RunsWhileWorking {
            /**
             * @param Closure(LimitedControls, string): void $run
             */
            public function __construct(
                private readonly Closure $run,
                private readonly string $commandName,
            ) {
            }

            public function name(): string
            {
                return $this->commandName;
            }

            public function describe(): string
            {
                return 'Does what the test says, working or not.';
            }

            public function run(
                LimitedControls $controls,
                string $arguments,
            ): void {
                ($this->run)($controls, $arguments);
            }
        };
    }

    /**
     * The commands Neuron CLI ships for the Sessions of one provider.
     *
     * @return list<SlashCommand|RunsWhileWorking>
     */
    private static function sessionCommands(
        SessionProvider $sessions,
    ): array {
        return [
            new Clear($sessions),
            new Sessions($sessions),
            new Leave(),
        ];
    }

    public function testTheDefaultSessionProviderWritesNothingToDisk(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('An answer.'));
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $terminal = new VirtualTerminal(rows: 30);
        $pickerDisplay = null;
        $workingDirectory = getcwd();
        self::assertIsString($workingDirectory);
        $before = scandir($workingDirectory);
        EventLoop::delay(
            0.03,
            static fn () => $terminal->simulateInput("/clear\r"),
        );
        EventLoop::delay(
            0.09,
            static fn () => $terminal->simulateInput("A question\r"),
        );
        EventLoop::delay(
            0.3,
            static fn () => $terminal->simulateInput("/clear\r"),
        );
        EventLoop::delay(
            0.38,
            static fn () => $terminal->simulateInput("/sessions\r"),
        );
        EventLoop::delay(
            0.46,
            static function () use (&$pickerDisplay, $terminal): void {
                $pickerDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->clearOutput();
                $terminal->simulateInput("\r");
            },
        );
        EventLoop::delay(
            0.6,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: self::sessionCommands(new InMemorySessionProvider()),
        ))->run();

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());

        self::assertIsString($pickerDisplay);
        self::assertStringContainsString('A question', $pickerDisplay);
        self::assertStringContainsString('❯ A question', $display);
        self::assertStringContainsString('● An answer.', $display);
        self::assertSame(
            ['A question', 'An answer.'],
            array_map(
                static fn (Message $message): string => (string) $message
                    ->getContent(),
                $agent->getChatHistory()->getMessages(),
            ),
        );
        // The directory the old default invented is named because it is the
        // one a Host Application would have found; the scan says nothing else
        // arrived either.
        self::assertDirectoryDoesNotExist(
            $workingDirectory . '/.neuron',
        );
        self::assertSame($before, scandir($workingDirectory));
    }

    public function testClearOpensAnEmptySessionOverTheOneOnScreen(): void
    {
        $earlier = new ExistingChatHistory([
            new UserMessage('Earlier question.'),
            new AssistantMessage('Earlier answer.'),
        ]);
        $agent = new Agent();
        $agent->setChatHistory($earlier);
        $sessions = new InMemorySessionProvider();
        $terminal = new VirtualTerminal(rows: 24);
        $clearedDisplay = null;
        EventLoop::delay(
            0.04,
            static fn () => $terminal->simulateInput("/clear\r"),
        );
        EventLoop::delay(
            0.09,
            static function () use ($terminal): void {
                $terminal->clearOutput();
                $terminal->simulateInput('draft');
            },
        );
        EventLoop::delay(
            0.14,
            static function () use (&$clearedDisplay, $terminal): void {
                $clearedDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->simulateInput("\x03");
            },
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: self::sessionCommands($sessions),
        ))->run();

        self::assertIsString($clearedDisplay);
        self::assertStringContainsString('draft', $clearedDisplay);
        self::assertStringNotContainsString(
            'Earlier question.',
            $clearedDisplay,
        );
        self::assertStringNotContainsString(
            'Earlier answer.',
            $clearedDisplay,
        );
        self::assertStringNotContainsString('/clear', $clearedDisplay);
        self::assertSame([], $agent->getChatHistory()->getMessages());
        self::assertCount(2, $earlier->getMessages());
        // Nobody wrote in the Session that was just started, so there is
        // nothing to return to until something is written — and what the
        // provider then lists is the conversation the Agent is holding.
        self::assertCount(0, $sessions->list());
        $agent->getChatHistory()->addMessage(new UserMessage('Written later'));
        $listed = $sessions->list();
        self::assertCount(1, $listed);
        self::assertSame('Written later', $listed[0]->title);
    }

    public function testClearLeavesTheConversationItReplacedStored(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('An answer.'));
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $sessions = new InMemorySessionProvider();
        $terminal = new VirtualTerminal(rows: 24);
        EventLoop::delay(
            0.03,
            static fn () => $terminal->simulateInput("/clear\r"),
        );
        EventLoop::delay(
            0.08,
            static fn () => $terminal->simulateInput("A question\r"),
        );
        EventLoop::delay(
            0.25,
            static fn () => $terminal->simulateInput("/clear\r"),
        );
        EventLoop::delay(
            0.35,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: self::sessionCommands($sessions),
        ))->run();

        $listed = $sessions->list();

        self::assertCount(1, $listed);
        self::assertSame('A question', $listed[0]->title);
        self::assertSame(
            ['A question', 'An answer.'],
            array_map(
                static fn (Message $message): string => (string) $message
                    ->getContent(),
                $sessions->open($listed[0]->key)->getMessages(),
            ),
        );
        self::assertSame([], $agent->getChatHistory()->getMessages());
        // The Session the Agent was left holding is the newer one the
        // provider minted, so writing in it lists it ahead of the other.
        $agent->getChatHistory()->addMessage(new UserMessage('Written later'));
        self::assertSame(
            ['Written later', 'A question'],
            array_map(
                static fn (Session $session): string => $session->title,
                $sessions->list(),
            ),
        );
    }

    public function testClearIsRefusedWhileTheAgentIsWorkingButExitIsNot(): void
    {
        $forcedExit = false;
        $refusedDisplay = null;
        $provider = new class(
            new AssistantMessage('A slow answer.'),
        ) extends FakeAIProvider {
            protected function streamChunks(Message $response): Generator
            {
                \Amp\delay(0.5);
                yield new TextChunk('slow-stream', 'A slow answer.');

                return $response;
            }
        };
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $ongoing = $agent->getChatHistory();
        $sessions = new InMemorySessionProvider();
        $terminal = new VirtualTerminal(rows: 24);
        EventLoop::queue(
            static fn () => $terminal->simulateInput("A question\r"),
        );
        EventLoop::delay(
            0.06,
            static fn () => $terminal->simulateInput("/clear\r"),
        );
        EventLoop::delay(
            0.12,
            static function () use (&$refusedDisplay, $terminal): void {
                $refusedDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                // A refused command stays in the composer, the way an
                // unknown one does, so the first Escape takes away the band
                // that says nothing matches and the second the name.
                $terminal->simulateInput("\x1b");
                $terminal->simulateInput("\x1b");
                $terminal->simulateInput("/exit\r");
            },
        );
        EventLoop::delay(
            0.9,
            static function () use (&$forcedExit, $terminal): void {
                $forcedExit = true;
                $terminal->simulateInput("\x03");
            },
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: self::sessionCommands($sessions),
        ))->run();

        self::assertIsString($refusedDisplay);
        self::assertStringContainsString(
            '/clear is refused while the Agent is working',
            $refusedDisplay,
        );
        self::assertStringContainsString('❯ A question', $refusedDisplay);
        self::assertSame([], $sessions->list());
        self::assertSame($ongoing, $agent->getChatHistory());
        self::assertFalse($forcedExit);
    }

    public function testSessionsListsStoredConversationsAndResumesOne(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('A later answer.'),
        );
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $sessions = new InMemorySessionProvider();
        $earlier = $sessions->open($sessions->create()->key);
        $earlier->addMessage(new UserMessage('The earlier subject'));
        $earlier->addMessage(new Message(MessageRole::ASSISTANT, [
            new ReasoningContent('Private chain of thought.'),
            new TextContent('The earlier answer.'),
        ]));
        $terminal = new VirtualTerminal(rows: 30);
        $pickerDisplay = null;
        EventLoop::delay(
            0.04,
            static fn () => $terminal->simulateInput("/sessions\r"),
        );
        EventLoop::delay(
            0.1,
            static function () use (&$pickerDisplay, $terminal): void {
                $pickerDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->clearOutput();
                $terminal->simulateInput("\r");
            },
        );
        EventLoop::delay(
            0.16,
            static fn () => $terminal->simulateInput("A follow-up\r"),
        );
        EventLoop::delay(
            0.4,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: self::sessionCommands($sessions),
        ))->run();

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());

        self::assertIsString($pickerDisplay);
        self::assertStringContainsString(
            'The earlier subject',
            $pickerDisplay,
        );
        self::assertStringContainsString('❯ The earlier subject', $display);
        self::assertStringContainsString('● The earlier answer.', $display);
        self::assertStringNotContainsString(
            'Private chain of thought.',
            $display,
        );
        self::assertSame($earlier, $agent->getChatHistory());
        $provider->assertSent(
            static fn (RequestRecord $request): bool => array_map(
                static fn (Message $message): string => $message->getRole(),
                $request->messages,
            ) === ['user', 'assistant', 'user']
                && (string) $request->messages[0]->getContent()
                    === 'The earlier subject',
        );
    }

    /**
     * Leaving the picker takes the list off screen, and a list off screen is
     * a list the TUI has detached — which is where the answers to Enter and
     * Escape were being lost. So a second opening has to answer both again,
     * or a person is left in a list that resumes nothing and will not close.
     */
    public function testTheSessionPickerStillResumesWhenOpenedASecondTime(): void
    {
        $agent = new Agent();
        $agent->setAiProvider(new FakeAIProvider(
            new AssistantMessage('A later answer.'),
        ));
        $sessions = new InMemorySessionProvider();
        $earlier = $sessions->open($sessions->create()->key);
        $earlier->addMessage(new UserMessage('The earlier subject'));
        $terminal = new VirtualTerminal(rows: 30);
        EventLoop::delay(
            0.04,
            static fn () => $terminal->simulateInput("/sessions\r"),
        );
        EventLoop::delay(
            0.1,
            static fn () => $terminal->simulateInput("\x1b"),
        );
        EventLoop::delay(
            0.16,
            static fn () => $terminal->simulateInput("/sessions\r"),
        );
        EventLoop::delay(
            0.22,
            static function () use ($terminal): void {
                $terminal->clearOutput();
                $terminal->simulateInput("\r");
            },
        );
        EventLoop::delay(
            0.4,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: self::sessionCommands($sessions),
        ))->run();

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());

        self::assertStringNotContainsString('Enter resumes', $display);
        self::assertStringContainsString('❯ The earlier subject', $display);
        self::assertSame($earlier, $agent->getChatHistory());
    }

    /**
     * A null byte is the one character that could confuse a picker packing a
     * title and a key into a single value, so a title carrying one is what
     * pins the picker to carrying Sessions instead: the title is displayed
     * and nothing else, and the Session chosen is the Session opened.
     */
    public function testASessionTitledWithANullByteIsListedAndResumed(): void
    {
        $agent = new Agent();
        $sessions = new InMemorySessionProvider();
        $earlier = $sessions->open($sessions->create()->key);
        $earlier->addMessage(new UserMessage("The earlier\x00 subject"));
        $terminal = new VirtualTerminal(rows: 24);
        $pickerDisplay = null;
        EventLoop::delay(
            0.04,
            static fn () => $terminal->simulateInput("/sessions\r"),
        );
        EventLoop::delay(
            0.1,
            static function () use (&$pickerDisplay, $terminal): void {
                $pickerDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->clearOutput();
                $terminal->simulateInput("\r");
            },
        );
        EventLoop::delay(
            0.2,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: self::sessionCommands($sessions),
        ))->run();

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());

        self::assertIsString($pickerDisplay);
        self::assertStringContainsString(
            'The earlier subject',
            $pickerDisplay,
        );
        self::assertStringContainsString('❯ The earlier subject', $display);
        self::assertSame($earlier, $agent->getChatHistory());
    }

    public function testEscapeLeavesTheSessionPickerWithTheSameSession(): void
    {
        $agent = new Agent();
        $ongoing = $agent->getChatHistory();
        $sessions = new InMemorySessionProvider();
        $sessions->open($sessions->create()->key)->addMessage(
            new UserMessage('The earlier subject'),
        );
        $terminal = new VirtualTerminal(rows: 24);
        $pickerDisplay = null;
        EventLoop::delay(
            0.04,
            static fn () => $terminal->simulateInput("/sessions\r"),
        );
        EventLoop::delay(
            0.1,
            static function () use (&$pickerDisplay, $terminal): void {
                $pickerDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->clearOutput();
                // Typed at the list, not at the composer, so nothing of it
                // is left behind once the picker closes.
                $terminal->simulateInput('zzz');
            },
        );
        EventLoop::delay(
            0.16,
            static function () use ($terminal): void {
                $terminal->clearOutput();
                $terminal->simulateInput("\x1b");
            },
        );
        EventLoop::delay(
            0.26,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: self::sessionCommands($sessions),
        ))->run();

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());

        self::assertIsString($pickerDisplay);
        self::assertStringContainsString(
            'The earlier subject',
            $pickerDisplay,
        );
        self::assertStringNotContainsString('The earlier subject', $display);
        self::assertStringNotContainsString('/sessions', $display);
        self::assertStringNotContainsString('zzz', $display);
        self::assertStringContainsString('ready · Enter sends', $display);
        self::assertSame($ongoing, $agent->getChatHistory());
    }

    public function testSessionsSaysSoWhenThereIsNothingToReturnTo(): void
    {
        $agent = new Agent();
        $terminal = new VirtualTerminal(rows: 24);
        EventLoop::delay(
            0.04,
            static fn () => $terminal->simulateInput("/sessions\r"),
        );
        EventLoop::delay(
            0.12,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: self::sessionCommands(new InMemorySessionProvider()),
        ))->run();

        self::assertStringContainsString(
            'There is no earlier Session to return to yet.',
            AnsiUtils::stripAnsiCodes($terminal->getOutput()),
        );
    }

    public function testTypingNarrowsThePickerInsteadOfTheComposer(): void
    {
        $agent = new Agent();
        $sessions = new InMemorySessionProvider();
        $sessions->open($sessions->create()->key)->addMessage(
            new UserMessage('Alpha subject'),
        );
        $beta = $sessions->open($sessions->create()->key);
        $beta->addMessage(new UserMessage('Beta subject'));

        foreach (['Gamma', 'Delta', 'Epsilon', 'Zeta'] as $subject) {
            $sessions->open($sessions->create()->key)->addMessage(
                new UserMessage($subject . ' subject'),
            );
        }

        $terminal = new VirtualTerminal(rows: 24);
        $narrowedDisplay = null;
        EventLoop::delay(
            0.04,
            static fn () => $terminal->simulateInput("/sessions\r"),
        );
        EventLoop::delay(
            0.1,
            static function () use ($terminal): void {
                $terminal->clearOutput();
                $terminal->simulateInput('Beta');
            },
        );
        EventLoop::delay(
            0.16,
            static function () use (&$narrowedDisplay, $terminal): void {
                $narrowedDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->simulateInput("\r");
            },
        );
        EventLoop::delay(
            0.26,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: self::sessionCommands($sessions),
        ))->run();

        self::assertIsString($narrowedDisplay);
        self::assertStringContainsString('Beta subject', $narrowedDisplay);
        self::assertStringNotContainsString('Alpha subject', $narrowedDisplay);
        self::assertSame($beta, $agent->getChatHistory());
    }

    public function testArrowKeysChooseAnotherSessionInThePicker(): void
    {
        $agent = new Agent();
        $sessions = new InMemorySessionProvider();
        $older = $sessions->open($sessions->create()->key);
        $older->addMessage(new UserMessage('The older subject'));
        $sessions->open($sessions->create()->key)->addMessage(
            new UserMessage('The newer subject'),
        );
        $terminal = new VirtualTerminal(rows: 24);
        EventLoop::delay(
            0.04,
            static fn () => $terminal->simulateInput("/sessions\r"),
        );
        EventLoop::delay(
            0.1,
            static fn () => $terminal->simulateInput("\x1b[B"),
        );
        EventLoop::delay(
            0.16,
            static fn () => $terminal->simulateInput("\r"),
        );
        EventLoop::delay(
            0.26,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: self::sessionCommands($sessions),
        ))->run();

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());

        self::assertStringContainsString('❯ The older subject', $display);
        self::assertSame($older, $agent->getChatHistory());
    }

    public function testSessionsIsRefusedWhileTheAgentIsWorking(): void
    {
        $refusedDisplay = null;
        $provider = new class(
            new AssistantMessage('A slow answer.'),
        ) extends FakeAIProvider {
            protected function streamChunks(Message $response): Generator
            {
                \Amp\delay(0.4);
                yield new TextChunk('slow-stream', 'A slow answer.');

                return $response;
            }
        };
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $sessions = new InMemorySessionProvider();
        $sessions->open($sessions->create()->key)->addMessage(
            new UserMessage('The earlier subject'),
        );
        $terminal = new VirtualTerminal(rows: 24);
        EventLoop::queue(
            static fn () => $terminal->simulateInput("A question\r"),
        );
        EventLoop::delay(
            0.06,
            static fn () => $terminal->simulateInput("/sessions\r"),
        );
        EventLoop::delay(
            0.12,
            static function () use (&$refusedDisplay, $terminal): void {
                $refusedDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->simulateInput("\x03");
            },
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: self::sessionCommands($sessions),
        ))->run();

        self::assertIsString($refusedDisplay);
        self::assertStringContainsString(
            '/sessions is refused while the Agent is working',
            $refusedDisplay,
        );
        self::assertStringNotContainsString(
            'The earlier subject',
            $refusedDisplay,
        );
    }

    /**
     * A kit is mounted in one line and every command it offers answers, the
     * way each would have answered had it been named on its own.
     */
    public function testMountingAKitMountsEveryCommandItOffers(): void
    {
        $agent = new Agent();
        $agent->setAiProvider(new FakeAIProvider());
        $sessions = new InMemorySessionProvider();
        $earlier = $sessions->open($sessions->create()->key);
        $earlier->addMessage(new UserMessage('The earlier subject'));
        $earlier->addMessage(new AssistantMessage('The earlier answer.'));
        $terminal = new VirtualTerminal(rows: 30);
        $pickerDisplay = null;
        $resumedDisplay = null;
        $clearedDisplay = null;
        EventLoop::delay(
            0.04,
            static fn () => $terminal->simulateInput("/sessions\r"),
        );
        EventLoop::delay(
            0.1,
            static function () use (&$pickerDisplay, $terminal): void {
                $pickerDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->clearOutput();
                $terminal->simulateInput("\r");
            },
        );
        EventLoop::delay(
            0.16,
            static function () use (&$resumedDisplay, $terminal): void {
                $resumedDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->clearOutput();
                $terminal->simulateInput("/clear\r");
            },
        );
        EventLoop::delay(
            0.24,
            static function () use ($terminal): void {
                $terminal->clearOutput();
                $terminal->simulateInput('draft');
            },
        );
        EventLoop::delay(
            0.3,
            static function () use (&$clearedDisplay, $terminal): void {
                $clearedDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->simulateInput("\x03");
            },
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: [new SessionKit($sessions), new Leave()],
        ))->run();

        self::assertIsString($pickerDisplay);
        self::assertStringContainsString(
            'The earlier subject',
            $pickerDisplay,
        );
        self::assertIsString($resumedDisplay);
        self::assertStringContainsString(
            '● The earlier answer.',
            $resumedDisplay,
        );
        self::assertIsString($clearedDisplay);
        self::assertStringContainsString('draft', $clearedDisplay);
        self::assertStringNotContainsString(
            'The earlier subject',
            $clearedDisplay,
        );
        self::assertSame([], $agent->getChatHistory()->getMessages());
        // Both commands reached the one provider the kit was given, so the
        // Session the first resumed is the one the second left stored.
        self::assertSame(
            ['The earlier subject'],
            array_map(
                static fn (Session $session): string => $session->title,
                $sessions->list(),
            ),
        );
    }

    public function testAKitCanBeMountedWithSomeOfItsCommandsLeftOut(): void
    {
        $agent = new Agent();
        $agent->setAiProvider(new FakeAIProvider());
        $sessions = new InMemorySessionProvider();
        $earlier = $sessions->open($sessions->create()->key);
        $earlier->addMessage(new UserMessage('The earlier subject'));
        $terminal = new VirtualTerminal(rows: 30);
        $refusedDisplay = null;
        $pickerDisplay = null;
        EventLoop::delay(
            0.04,
            static fn () => $terminal->simulateInput("/clear\r"),
        );
        EventLoop::delay(
            0.1,
            static function () use (&$refusedDisplay, $terminal): void {
                $refusedDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                // An unknown name stays in the composer under the band that
                // says nothing matches, so the first Escape takes the band
                // away and the second the name.
                $terminal->simulateInput("\x1b");
                $terminal->simulateInput("\x1b");
                $terminal->clearOutput();
                $terminal->simulateInput("/sessions\r");
            },
        );
        EventLoop::delay(
            0.18,
            static function () use (&$pickerDisplay, $terminal): void {
                $pickerDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->simulateInput("\x1b");
                $terminal->simulateInput("\x03");
            },
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: [
                (new SessionKit($sessions))->exclude([Clear::class]),
                new Leave(),
            ],
        ))->run();

        self::assertIsString($refusedDisplay);
        self::assertStringContainsString(
            'Unknown Slash command: /clear',
            $refusedDisplay,
        );
        self::assertIsString($pickerDisplay);
        self::assertStringNotContainsString(
            'Unknown Slash command: /sessions',
            $pickerDisplay,
        );
        self::assertStringContainsString(
            'The earlier subject',
            $pickerDisplay,
        );
    }

    public function testAKitCanBeMountedKeepingOnlySomeOfItsCommands(): void
    {
        $agent = new Agent();
        $agent->setAiProvider(new FakeAIProvider());
        $sessions = new InMemorySessionProvider();
        $agent->setChatHistory(new ExistingChatHistory([
            new UserMessage('The earlier subject'),
            new AssistantMessage('The earlier answer.'),
        ]));
        $terminal = new VirtualTerminal(rows: 30);
        $refusedDisplay = null;
        $clearedDisplay = null;
        EventLoop::delay(
            0.04,
            static fn () => $terminal->simulateInput("/sessions\r"),
        );
        EventLoop::delay(
            0.1,
            static function () use (&$refusedDisplay, $terminal): void {
                $refusedDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->simulateInput("\x1b");
                $terminal->simulateInput("\x1b");
                $terminal->clearOutput();
                $terminal->simulateInput("/clear\r");
            },
        );
        EventLoop::delay(
            0.18,
            static function () use ($terminal): void {
                $terminal->clearOutput();
                $terminal->simulateInput('draft');
            },
        );
        EventLoop::delay(
            0.24,
            static function () use (&$clearedDisplay, $terminal): void {
                $clearedDisplay = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->simulateInput("\x03");
            },
        );

        (new NeuronCli(
            $agent,
            terminal: $terminal,
            commands: [
                (new SessionKit($sessions))->only([Clear::class]),
                new Leave(),
            ],
        ))->run();

        self::assertIsString($refusedDisplay);
        self::assertStringContainsString(
            'Unknown Slash command: /sessions',
            $refusedDisplay,
        );
        self::assertIsString($clearedDisplay);
        self::assertStringContainsString('draft', $clearedDisplay);
        self::assertStringNotContainsString(
            'Unknown Slash command: /clear',
            $clearedDisplay,
        );
        self::assertStringNotContainsString(
            'The earlier subject',
            $clearedDisplay,
        );
        self::assertSame([], $agent->getChatHistory()->getMessages());
    }

    /**
     * A command a kit brought is a mounted command like any other, so it
     * stops the construction when it answers to a name already taken.
     */
    public function testACommandFromAKitClaimingATakenNameStopsTheBuild(): void
    {
        $sessions = new InMemorySessionProvider();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Two Slash commands answer to /clear.',
        );

        new NeuronCli(
            new Agent(),
            terminal: new VirtualTerminal(),
            commands: [new Clear($sessions), new SessionKit($sessions)],
        );
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
