<?php

declare(strict_types=1);

namespace NeuronTui\Tests;

use Closure;
use Generator;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolCall;
use NeuronInteraction\Conversation;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronTui\Tui;
use NeuronTui\View\MainView;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Revolt\EventLoop;
use RuntimeException;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\ScreenBuffer;
use Symfony\Component\Tui\Terminal\TerminalInterface;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui as SymfonyTui;

use function array_merge;
use function count;
use function explode;
use function implode;
use function rtrim;
use function str_contains;
use function str_repeat;
use function str_replace;
use function trim;

final class TuiTest extends TestCase
{
    public function testComposerGrowsForNewlinesAndSubmitsTheWholeMessage(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Received both lines'));
        $agent = Agent::make()->setAiProvider($provider);
        $stage = 0;
        $this->runApplication($agent, function (VirtualTerminal $terminal, string $screen) use (&$stage): bool {
            $lines = explode("\n", $screen);
            if ($stage === 0) {
                $terminal->simulateInput("  First line\x1b[13;2uSecond line  ");
                $stage = 1;
            } elseif ($stage === 1 && str_contains($screen, 'Second line')) {
                self::assertSame('✻ Ready', rtrim($lines[34]));
                self::assertSame('❯   First line', rtrim($lines[36]));
                self::assertSame('  Second line', rtrim($lines[37]));
                self::assertSame('Enter send · Shift+Enter newline · Ctrl+C exit', rtrim($lines[39]));
                $terminal->simulateInput("\r");
                $stage = 2;
            } elseif (
                $stage === 2
                && str_contains($screen, 'Received both lines')
                && !str_contains($screen, 'Working')
            ) {
                self::assertSame('✻ Ready', rtrim($lines[35]));
                self::assertSame('❯', rtrim($lines[37]));

                return true;
            }

            return false;
        });
        self::assertSame("First line\nSecond line", $provider->getRecorded()[0]->messages[0]->getContent());
        $provider->assertCallCount(1);
    }

    public function testLongDraftShowsFiveLinesAndSubmitsTheWholeMessage(): void
    {
        $prompt = "Line 1\nLine 2\nLine 3\nLine 4\nLine 5\nLine 6\nLine 7\nLine 8";
        $provider = new FakeAIProvider(new AssistantMessage('Received eight lines'));
        $stage = 0;
        $this->runApplication(Agent::make()->setAiProvider($provider), function (
            VirtualTerminal $terminal,
            string $screen,
        ) use ($prompt, &$stage): bool {
            if ($stage === 0) {
                $terminal->simulateInput(str_replace("\n", "\x1b[13;2u", $prompt));
                $stage = 1;
            } elseif ($stage === 1 && str_contains($screen, 'Line 8')) {
                $lines = explode("\n", $screen);
                self::assertSame('✻ Ready', rtrim($lines[31]));
                self::assertStringStartsWith('❯', $lines[33]);
                self::assertSame('  Line 8', rtrim($lines[37]));
                self::assertStringStartsWith('Enter send', $lines[39]);
                $terminal->simulateInput("\r");
                $stage = 2;
            }
            return str_contains($screen, 'Received eight lines') && str_contains($screen, 'Ready');
        });
        $provider->assertCallCount(1);
        self::assertSame($prompt, $provider->getRecorded()[0]->messages[0]->getContent());
    }

    public function testWorkingIndicatorAnimatesAndReturnsToReady(): void
    {
        $agent = Agent::make()->setAiProvider(new FakeAIProvider());
        $sent = false;
        $frames = [];
        $draftSent = false;
        $sawDraftWhileWorking = false;
        $this->runApplication($agent, function (
            VirtualTerminal $terminal,
            string $screen,
        ) use (&$sent, &$frames, &$draftSent, &$sawDraftWhileWorking): bool {
            if (!$sent) {
                $terminal->simulateInput("Animate\r");
                $sent = true;

                return false;
            }
            $lines = explode("\n", $screen);
            if (str_contains($lines[35], 'Working')) {
                $frames[rtrim($lines[35])] = true;
                if (!$draftSent) {
                    $terminal->simulateInput("Next question");
                    $draftSent = true;
                } elseif (str_contains($lines[37], 'Next question')) {
                    $sawDraftWhileWorking = true;
                }
            } elseif ($frames !== [] && str_contains($lines[35], 'Ready')) {
                self::assertGreaterThanOrEqual(2, count($frames));
                self::assertSame('✻ Ready', rtrim($lines[35]));
                self::assertTrue($sawDraftWhileWorking);
                self::assertSame('❯ Next question', rtrim($lines[37]));

                return true;
            }

            return false;
        }, function (MainView $view): void {
            $view->onInput(function (string $input) use ($view): void {
                EventLoop::delay(0.3, $view->finishTurn(...));
            });
        });
    }

    public function testGrowingHistoryQueuesMessagesInOrderAndPreservesTheDraft(): void
    {
        $answer = [];
        for ($line = 1; $line <= 80; ++$line) {
            $answer[] = "Response line {$line}";
        }
        $provider = (new class (
            new AssistantMessage(implode("\n", $answer)),
            new AssistantMessage('Second response'),
            new AssistantMessage('Third response'),
        ) extends FakeAIProvider {
            /** @return Generator<int, StreamChunk, mixed, ProviderResponse> */
            protected function streamChunks(Message $response): Generator
            {
                $stream = parent::streamChunks($response);
                foreach ($stream as $chunk) {
                    $suspension = EventLoop::getSuspension();
                    EventLoop::delay(0.005, static fn() => $suspension->resume());
                    $suspension->suspend();
                    yield $chunk;
                }
                return $stream->getReturn();
            }
        })->setStreamChunkSize(80);
        $stage = 0;
        $this->runApplication(Agent::make()->setAiProvider($provider), function (
            VirtualTerminal $terminal,
            string $screen,
        ) use (&$stage): bool {
            $lines = explode("\n", $screen);
            self::assertStringStartsWith('❯', $lines[37], $screen);
            self::assertStringStartsWith('Enter send', $lines[39], $screen);
            if ($stage === 0) {
                $terminal->simulateInput("Start\r");
                $stage = 1;
            } elseif ($stage === 1 && str_contains($screen, 'Response line 40')) {
                $terminal->simulateInput("Second input\rThird input\rUnsubmitted draft");
                $stage = 2;
            }
            if (str_contains($screen, 'Third response') && str_contains($screen, 'Ready')) {
                self::assertSame('❯ Unsubmitted draft', rtrim($lines[37]));
                return true;
            }
            return false;
        }, verify: static function (ScreenBuffer $buffer, string $output): void {
            $history = AnsiUtils::stripAnsiCodes(implode(
                "\n",
                array_merge($buffer->getScrollback(), $buffer->getLines()),
            ));
            self::assertStringContainsString('Response line 1 ', $history);
            self::assertStringContainsString('Response line 80', $history);
            self::assertStringNotContainsString(
                'Working',
                AnsiUtils::stripAnsiCodes(implode("\n", $buffer->getScrollback())),
            );
            self::assertStringNotContainsString("\x1b[?1000h", $output);
            self::assertStringNotContainsString("\x1b[?1006h", $output);
        });
        self::assertSame(2, $stage);
        $provider->assertCallCount(3);
        self::assertSame('Second input', $provider->getRecorded()[1]->messages[2]->getContent());
        self::assertSame('Third input', $provider->getRecorded()[2]->messages[4]->getContent());
    }

    public function testStreamsAndContinuesTheSuppliedConversation(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Hello world'), new AssistantMessage('Second answer'));
        $agent = Agent::make()->setThreadId('existing')->setAiProvider($provider);
        $agent->getChatHistory()->addMessage(new UserMessage('Earlier question'));
        $agent->getChatHistory()->addMessage(new AssistantMessage('Earlier answer'));
        $conversation = $this->conversation($agent);
        $stage = 0;
        $sawPartial = false;
        $screen = $this->runApplication($conversation, function (
            VirtualTerminal $terminal,
            string $screen,
        ) use (&$stage, &$sawPartial): bool {
            if ($stage === 0) {
                self::assertStringContainsString('Earlier answer', $screen);
                $terminal->simulateInput("\r");
                $terminal->simulateInput("First question\r");
                $stage = 1;
            } elseif ($stage === 1) {
                $sawPartial = $sawPartial
                    || (str_contains($screen, '● Hello') && !str_contains($screen, 'Hello world'));
                if (str_contains($screen, 'Hello world') && !str_contains($screen, 'Working')) {
                    $terminal->simulateInput("Second question\r");
                    $stage = 2;
                }
            } elseif (str_contains($screen, 'Second answer') && !str_contains($screen, 'Working')) {
                return true;
            }
            return false;
        });
        self::assertTrue($sawPartial, 'The answer should appear before the stream completes.');
        self::assertStringContainsString('Second answer', $screen);
        $provider->assertCallCount(2);
        self::assertSame($conversation->session()->getKey(), $conversation->agent()->getThreadId());
        self::assertCount(6, $conversation->getMessages());
        self::assertSame('Earlier question', $provider->getRecorded()[0]->messages[0]->getContent());
    }

    public function testExecutesAndShowsTools(): void
    {
        $tool = new class extends Tool {
            protected string $name = 'weather';

            public function __invoke(): string
            {
                return 'Sunny';
            }
        };
        $provider = new FakeAIProvider(
            new ToolCallMessage('Checking', [new ToolCall('weather', 'call-1')]),
            new AssistantMessage('It is sunny'),
        );
        $agent = Agent::make()->setAiProvider($provider)->addTool($tool);
        $sent = false;
        $screen = $this->runApplication($agent, function (
            VirtualTerminal $terminal,
            string $screen,
        ) use (&$sent): bool {
            if (!$sent) {
                $terminal->simulateInput("Weather?\r");
                $sent = true;
            }
            return str_contains($screen, 'It is sunny') && !str_contains($screen, 'Working');
        });
        self::assertStringContainsString('● weather', $screen);
        self::assertStringContainsString('Checking', $screen);
        $provider->assertCallCount(2);
    }

    public function testShowsErrorsAndAllowsAnotherTurn(): void
    {
        $provider = new FakeAIProvider();
        $agent = Agent::make()->setAiProvider($provider);
        $stage = 0;
        $screen = $this->runApplication($agent, function (
            VirtualTerminal $terminal,
            string $screen,
        ) use ($provider, &$stage): bool {
            if ($stage === 0) {
                $terminal->simulateInput("Fail\r");
                $stage = 1;
            } elseif ($stage === 1 && str_contains($screen, '! FakeAIProvider')) {
                $provider->addResponses(new AssistantMessage('Recovered'));
                $terminal->simulateInput("Try again\r");
                $stage = 2;
            }
            return str_contains($screen, 'Recovered') && !str_contains($screen, 'Working');
        });
        self::assertStringContainsString('! FakeAIProvider', $screen);
        self::assertStringContainsString('Recovered', $screen);
    }

    public function testQueuedMessageRunsAfterTheCurrentTurnFails(): void
    {
        $provider = new class (new AssistantMessage('Recovered queued turn')) extends FakeAIProvider {
            private bool $failed = false;

            /** @return Generator<int, StreamChunk, mixed, ProviderResponse> */
            public function stream(Message ...$messages): Generator
            {
                if (!$this->failed) {
                    $this->failed = true;
                    throw new RuntimeException('First turn failed');
                }
                return parent::stream(...$messages);
            }
        };
        $sent = false;
        $screen = $this->runApplication(Agent::make()->setAiProvider($provider), function (
            VirtualTerminal $terminal,
            string $screen,
        ) use (&$sent): bool {
            if (!$sent) {
                $terminal->simulateInput("Fail\rQueued retry\r");
                $sent = true;
            }
            return str_contains($screen, 'Recovered queued turn') && str_contains($screen, 'Ready');
        });
        self::assertStringContainsString('! First turn failed', $screen);
        $provider->assertCallCount(1);
        $messages = $provider->getRecorded()[0]->messages;
        self::assertSame('Queued retry', $messages[count($messages) - 1]->getContent());
    }

    public function testQuittingDuringStreamingDiscardsQueuedMessages(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Long answer to interrupt'));
        $sent = false;
        $agent = Agent::make()->setAiProvider($provider);
        $this->runApplication($agent, function (VirtualTerminal $terminal, string $screen) use (&$sent): bool {
            if (!$sent) {
                $terminal->simulateInput("Start\r");
                $sent = true;
            } elseif (str_contains($screen, 'Working') && str_contains($screen, '● Long')) {
                $terminal->simulateInput("Ignored\r");
                return true;
            }
            return false;
        });
        $provider->assertCallCount(1);
        self::assertSame('Start', $provider->getRecorded()[0]->messages[0]->getContent());
    }

    public function testInputAndEscapeCallbacksFollowTheExample(): void
    {
        $provider = new FakeAIProvider();
        $received = [];
        $escapes = 0;
        $stage = 0;
        $screen = $this->runApplication(
            Agent::make()->setAiProvider($provider),
            function (VirtualTerminal $terminal, string $screen) use (&$stage): bool {
                if ($stage === 0) {
                    $terminal->simulateInput("\x1b");
                    $terminal->simulateInput("\r");
                    $terminal->simulateInput("Custom input\r");
                    $stage = 1;
                } elseif ($stage === 1 && str_contains($screen, 'Custom answer')) {
                    $terminal->simulateInput("\x1b");
                    $stage = 2;
                } elseif ($stage === 2 && !str_contains($screen, 'Working')) {
                    return true;
                }
                return false;
            },
            function (MainView $host) use (&$received, &$escapes): void {
                $host->onInput(function (string $input) use ($host, &$received): void {
                    $received[] = $input;
                    $host->appendResponse('Custom answer');
                });
                $host->onEscape(function () use ($host, &$escapes): void {
                    ++$escapes;
                    $host->notify('Escape received');
                    $host->finishTurn();
                });
            },
        );
        self::assertSame(['Custom input'], $received);
        self::assertSame(1, $escapes);
        self::assertStringContainsString('Escape received', $screen);
        $provider->assertNothingSent();
    }

    public function testHistorySyncCallbackSuppliesTheDisplayedMessages(): void
    {
        $agent = Agent::make()->setThreadId('history-sync')->setAiProvider(new FakeAIProvider());
        $agent->getChatHistory()->addMessage(new UserMessage('Stored message'));
        $syncs = 0;
        $screen = $this->runApplication(
            $agent,
            static fn(VirtualTerminal $terminal, string $screen): bool => str_contains($screen, 'Projected answer'),
            function (MainView $host) use (&$syncs): void {
                $host->onHistorySync(function () use (&$syncs): array {
                    ++$syncs;

                    return [new UserMessage('Projected question'), new AssistantMessage('Projected answer')];
                });
            },
        );
        self::assertSame(1, $syncs);
        self::assertStringNotContainsString('Stored message', $screen);
        self::assertSame('Stored message', $agent->getChatHistory()->getMessages()[0]->getContent());
    }

    public function testKeepsInputAtTheBottomAndLoadingDirectlyAboveIt(): void
    {
        $agent = Agent::make()
            ->setThreadId('layout')
            ->setAiProvider(new FakeAIProvider(new AssistantMessage('Last response')));
        $agent->getChatHistory()->addMessage(new UserMessage('Earlier question'));
        $agent->getChatHistory()->addMessage(new AssistantMessage(str_repeat("Older message\n", 80)));
        $stage = 0;
        $this->runApplication($agent, function (VirtualTerminal $terminal, string $screen) use (&$stage): bool {
            $lines = explode("\n", $screen);
            self::assertSame('Enter send · Shift+Enter newline · Ctrl+C exit', trim($lines[39]));
            self::assertSame('❯', trim($lines[37]));
            self::assertStringStartsWith('❯', $lines[37]);
            if ($stage === 0) {
                self::assertSame('✻ Ready', rtrim($lines[35]));
                $terminal->simulateInput("New question\r");
                $stage = 1;
            } elseif ($stage === 1 && str_contains($screen, 'Working')) {
                self::assertMatchesRegularExpression('/^[⠋⠙⠹⠸⠼⠴⠦⠧⠇⠏] Working…$/u', rtrim($lines[35]));
                $stage = 2;
            } elseif ($stage === 2 && str_contains($screen, 'Last response') && !str_contains($screen, 'Working')) {
                self::assertSame('✻ Ready', rtrim($lines[35]));
                return true;
            }
            return false;
        });
        self::assertSame(2, $stage);
    }

    public function testResizesWithAWrappedResponseAndKeepsTheLatestMessageVisible(): void
    {
        $answer = str_repeat("A long response that wraps across the narrower terminal window.\n", 40)
            . 'Latest response';
        $provider = (new FakeAIProvider(new AssistantMessage($answer)))->setStreamChunkSize(200);
        $stage = 0;
        $this->runApplication(Agent::make()->setAiProvider($provider), function (
            VirtualTerminal $terminal,
            string $screen,
        ) use (&$stage): bool {
            if ($stage === 0) {
                $terminal->simulateInput("Question\r");
                $stage = 1;
            } elseif ($stage === 1 && str_contains($screen, 'Working')) {
                $terminal->simulateResize(48, 18);
                $stage = 2;
            } elseif ($stage === 2) {
                $lines = explode("\n", $screen);
                self::assertCount(18, $lines);
                self::assertSame('❯', trim($lines[15]));
                self::assertStringStartsWith('❯', $lines[15]);
                self::assertSame('Enter send · Shift+Enter newline · Ctrl+C exit', trim($lines[17]));
                if (str_contains($screen, 'Latest response') && !str_contains($screen, 'Working')) {
                    self::assertSame('✻ Ready', rtrim($lines[13]));
                    return true;
                }
            }
            return false;
        });
    }

    private function conversation(Agent $agent): Conversation
    {
        $messages = $agent->getThreadId() === null ? [] : $agent->getChatHistory()->getMessages();
        $session = (new SessionStore(new InMemoryStorage(), 'test-user'))->create();
        $conversation = new Conversation($agent, $session);
        foreach ($messages as $message) {
            $conversation->agent()->getChatHistory()->addMessage($message);
        }
        return $conversation;
    }

    /**
     * @param Closure(VirtualTerminal, string): bool $step
     * @param Closure(MainView): void|null $configure
     * @param Closure(ScreenBuffer, string): void|null $verify
     */
    private function runApplication(
        Agent|Conversation $agent,
        Closure $step,
        ?Closure $configure = null,
        ?Closure $verify = null,
    ): string {
        $terminal = new VirtualTerminal(100, 40);
        // Exercise the physical-terminal path: growing frames must reach scrollback.
        $driver = $this->createStub(TerminalInterface::class);
        foreach ([
            'start',
            'stop',
            'write',
            'getColumns',
            'getRows',
            'isKittyProtocolActive',
            'moveBy',
            'hideCursor',
            'showCursor',
            'clearLine',
            'clearFromCursor',
            'clearScreen',
            'setTitle',
            'bell',
        ] as $method) {
            $driver->method($method)->willReturnCallback($terminal->$method(...));
        }
        $driver->method('isVirtual')->willReturn(false);
        $tui = new SymfonyTui(terminal: $driver);
        $conversation = $agent instanceof Conversation ? $agent : $this->conversation($agent);
        $host = Tui::make($conversation);
        $view = (new ReflectionProperty(Tui::class, 'mainView'))->getValue($host);
        self::assertInstanceOf(MainView::class, $view);
        $configure?->__invoke($view);
        // Replace only the terminal host; exercise the public entry point.
        (new ReflectionProperty(Tui::class, 'tui'))->setValue($host, $tui);
        $columns = 100;
        $rows = 40;
        $buffer = new ScreenBuffer($columns, $rows);
        $lastScreen = '';
        $timedOut = false;
        $output = '';
        $poll = EventLoop::repeat(0.0001, function () use (
            $terminal,
            $tui,
            &$buffer,
            &$columns,
            &$rows,
            $step,
            &$lastScreen,
            &$output,
        ): void {
            if ($columns !== $terminal->getColumns() || $rows !== $terminal->getRows()) {
                $columns = $terminal->getColumns();
                $rows = $terminal->getRows();
                $buffer = new ScreenBuffer($columns, $rows);
            }
            $tui->processRender();
            $frame = $terminal->consumeOutput();
            $output .= $frame;
            $buffer->write($frame);
            $lastScreen = AnsiUtils::stripAnsiCodes(implode("\n", $buffer->getLines()));
            if ($step($terminal, $lastScreen)) {
                $terminal->simulateInput("\x03");
            }
        });
        $timeout = EventLoop::delay(2, function () use ($tui, &$timedOut): void {
            $timedOut = true;
            $tui->stop();
        });
        try {
            $host->run();
        } finally {
            EventLoop::cancel($poll);
            EventLoop::cancel($timeout);
        }
        self::assertFalse($timedOut, $lastScreen);
        $verify?->__invoke($buffer, $output);

        return $lastScreen;
    }
}
