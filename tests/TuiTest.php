<?php

declare(strict_types=1);

namespace NeuronTui\Tests;

use ArrayIterator;
use Closure;
use Generator;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\StoppableHttpClient;
use NeuronAI\HttpClient\StreamInterface;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolCall;
use NeuronInteraction\Command\CommandContext;
use NeuronInteraction\Command\CommandInput;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\ExitCommand;
use NeuronInteraction\Command\HelpCommand;
use NeuronInteraction\Command\ResumeCommand;
use NeuronInteraction\Command\SelectionOption;
use NeuronInteraction\Conversation;
use NeuronInteraction\Event\SelectionRequest;
use NeuronInteraction\InputHistory\InputHistory;
use NeuronInteraction\Interruption\StopSignal;
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
use Symfony\Component\Tui\Widget\Figlet\FigletRenderer;
use Symfony\Component\Tui\Widget\Figlet\FontRegistry;

use function array_map;
use function array_merge;
use function count;
use function explode;
use function implode;
use function json_encode;
use function rtrim;
use function str_contains;
use function str_repeat;
use function str_replace;
use function strpos;
use function trim;

use const JSON_THROW_ON_ERROR;

final class TuiTest extends TestCase
{
    public function testFigletBannerRendersAboveTheCustomHeader(): void
    {
        $banner = (new FigletRenderer((new FontRegistry())->get('small')))->render('Hi');
        $screen = $this->runApplication(
            Agent::make()->setAiProvider(new FakeAIProvider()),
            static fn(VirtualTerminal $terminal, string $screen): bool => str_contains($screen, 'Banner description'),
            configureHost: static function (Tui $host): void {
                self::assertSame($host, $host->setFiglet('Hi', 'small'));
                $host->setTitle('Banner title')->setDescription('Banner description');
            },
        );
        $lines = explode("\n", $screen);
        foreach ($banner as $index => $line) {
            self::assertSame(rtrim($line), rtrim($lines[$index]));
        }
        self::assertSame('', rtrim($lines[count($banner)]));
        self::assertSame('✳ Banner title', rtrim($lines[count($banner) + 1]));
        self::assertSame('Banner description', rtrim($lines[count($banner) + 2]));
        self::assertSame('❯', trim($lines[37]));
    }

    public function testInputHistoryRecallsStoredCommandsAndRestoresTheDraft(): void
    {
        $storage = new InMemoryStorage();
        $history = new InputHistory($storage, 'test-user');
        $history->append(new UserMessage('Previous input'));
        $history->append(new UserMessage('/help'));
        $history->append(new UserMessage('/exit'));
        $conversation = $this->conversation(Agent::make()->setAiProvider(new FakeAIProvider()));
        $conversation->setInputHistory(new InputHistory($storage, 'test-user'));
        $conversation->setCommands(new Commands(new HelpCommand(), new ExitCommand()));
        $stage = 0;
        $this->runApplication($conversation, function (
            VirtualTerminal $terminal,
            string $screen,
        ) use (&$stage): bool {
            if ($stage === 0) {
                $terminal->simulateInput("Draft\x1b[A");
                $stage = 1;
            } elseif ($stage === 1 && str_contains($screen, '❯ /exit')) {
                $terminal->simulateInput("\x1b[A");
                $stage = 2;
            } elseif ($stage === 2 && str_contains($screen, '❯ /help')) {
                $terminal->simulateInput("\r");
                $stage = 3;
            } elseif ($stage === 3 && str_contains($screen, 'Ready') && str_contains($screen, 'Lists what can be typed')) {
                $terminal->simulateInput("New draft\x1b[A");
                $stage = 4;
            } elseif ($stage === 4 && str_contains($screen, '❯ /help')) {
                $terminal->simulateInput("\x1b[A");
                $stage = 5;
            } elseif ($stage === 5 && str_contains($screen, '❯ /exit')) {
                $terminal->simulateInput("\x1b[A\x1b[A");
                $stage = 6;
            } elseif ($stage === 6 && str_contains($screen, '❯ Previous input')) {
                $terminal->simulateInput("\x1b[B\x1b[B\x1b[B\x1b[B");
                $stage = 7;
            } elseif ($stage === 7 && str_contains($screen, '❯ New draft')) {
                return true;
            }
            return false;
        });
        self::assertSame(7, $stage);
        self::assertCount(4, $history->list());
    }

    public function testSelectionSubmitsTheOpaqueValueAndPreservesTheDraft(): void
    {
        $provider = new FakeAIProvider();
        $conversation = $this->conversation(Agent::make()->setAiProvider($provider));
        $command = new class implements CommandInterface {
            /** @var list<string> */
            public array $received = [];

            public function name(): string
            {
                return '/choose';
            }

            public function describe(): string
            {
                return 'Choose an option';
            }

            public function run(CommandContext $context, string $value): void
            {
                $this->received[] = $value;
                if ($value === '') {
                    $context->requestSelection(new SelectionRequest('/choose', 'Choose a language', [
                        new SelectionOption('English', 'English'),
                        new SelectionOption("  Italian\nwith spaces  ", 'Italian', 'Respond in Italian'),
                    ], 'Pick the response language'));
                } else {
                    $context->notify('Choice applied');
                }
            }
        };
        $conversation->setCommands(new Commands($command));
        $stage = 0;
        $screen = $this->runApplication($conversation, function (
            VirtualTerminal $terminal,
            string $screen,
        ) use (&$stage): bool {
            if ($stage === 0) {
                $terminal->simulateInput("/choose\rKeep my draft");
                $stage = 1;
            } elseif ($stage === 1 && str_contains($screen, 'Choose a language')) {
                self::assertStringContainsString('Pick the response language', $screen);
                self::assertStringContainsString('Respond in Italian', $screen);
                self::assertStringNotContainsString('❯ Keep my draft', $screen);
                $terminal->simulateInput("zzzz\r");
                $stage = 2;
            } elseif ($stage === 2 && str_contains($screen, 'No matching options.')) {
                self::assertStringNotContainsString('Choice applied', $screen);
                $terminal->simulateInput("\x15itali");
                $stage = 3;
            } elseif ($stage === 3 && str_contains($screen, '❯ Italian')) {
                $terminal->simulateInput("\r");
                $stage = 4;
            } elseif ($stage === 4 && str_contains($screen, 'Choice applied') && str_contains($screen, 'Ready')) {
                return true;
            }
            return false;
        });
        self::assertSame(4, $stage);
        self::assertStringContainsString('❯ Keep my draft', $screen);
        self::assertStringNotContainsString('Choose a language', $screen);
        self::assertSame(['', "  Italian\nwith spaces  "], $command->received);
        $provider->assertNothingSent();
    }

    public function testMultipleSelectionRequestsArePresentedInOrder(): void
    {
        $provider = new FakeAIProvider();
        $conversation = $this->conversation(Agent::make()->setAiProvider($provider));
        $command = new class implements CommandInterface {
            /** @var list<string> */
            public array $received = [];

            public function name(): string
            {
                return '/choose';
            }

            public function describe(): string
            {
                return 'Choose two options';
            }

            public function run(CommandContext $context, string $value): void
            {
                $this->received[] = $value;
                if ($value === '') {
                    $context->requestSelection(new SelectionRequest('/choose', 'First choice', [
                        new SelectionOption('first', 'First option'),
                    ]));
                    $context->requestSelection(new SelectionRequest('/choose', 'Second choice', [
                        new SelectionOption('second', 'Second option'),
                    ]));
                } else {
                    $context->notify('Applied ' . $value);
                }
            }
        };
        $conversation->setCommands(new Commands($command));
        $stage = 0;
        $this->runApplication($conversation, function (
            VirtualTerminal $terminal,
            string $screen,
        ) use (&$stage): bool {
            if ($stage === 0) {
                $terminal->simulateInput("/choose\r");
                $stage = 1;
            } elseif ($stage === 1 && str_contains($screen, 'First choice')) {
                $terminal->simulateInput("\r");
                $stage = 2;
            } elseif ($stage === 2 && str_contains($screen, 'Second choice')) {
                $terminal->simulateInput("\r");
                $stage = 3;
            } elseif ($stage === 3 && str_contains($screen, 'Applied second') && str_contains($screen, 'Ready')) {
                return true;
            }
            return false;
        });
        self::assertSame(3, $stage);
        self::assertSame(['', 'first', 'second'], $command->received);
        $provider->assertNothingSent();
    }

    public function testResumeSelectionChangesTheSessionAndRefreshesHistory(): void
    {
        $nativeMessages = new InMemoryMessageStore();
        $provider = new FakeAIProvider();
        $agent = Agent::make()->setAiProvider($provider);
        $store = new SessionStore(new InMemoryStorage(), $nativeMessages, 'test-user');
        $saved = $store->create();
        $saved->setTitle('Saved conversation');
        $savedConversation = new Conversation($agent, $saved);
        $savedConversation->agent()->getChatHistory()->addMessage(new UserMessage('Saved question'));
        $savedConversation->agent()->getChatHistory()->addMessage(new AssistantMessage('Saved answer'));
        $saved->registerUse();
        $conversation = new Conversation($agent, $store->create());
        $conversation->setCommands(new Commands(new ResumeCommand($store)));
        $stage = 0;
        $screen = $this->runApplication($conversation, function (
            VirtualTerminal $terminal,
            string $screen,
        ) use (&$stage): bool {
            if ($stage === 0) {
                $terminal->simulateInput("/resume\r");
                $stage = 1;
            } elseif ($stage === 1 && str_contains($screen, 'Saved conversation')) {
                self::assertStringContainsString('Sessions (1/1)', $screen);
                $terminal->simulateInput("\r");
                $stage = 2;
            } elseif ($stage === 2 && str_contains($screen, 'Saved answer') && str_contains($screen, 'Ready')) {
                return true;
            }
            return false;
        });
        self::assertSame(2, $stage);
        self::assertSame($saved->getKey(), $conversation->session()->getKey());
        self::assertStringContainsString('Saved question', $screen);
        self::assertStringNotContainsString('Enter confirm', $screen);
        $provider->assertNothingSent();
    }

    public function testSelectionResponsesRunBeforeQueuedMessages(): void
    {
        $received = new class {
            /** @var list<string|CommandInput> */
            public array $inputs = [];
        };
        $stage = 0;
        $this->runApplication(
            Agent::make()->setAiProvider(new FakeAIProvider()),
            function (VirtualTerminal $terminal, string $screen) use (&$stage, $received): bool {
                if ($stage === 0) {
                    $terminal->simulateInput("Start\rQueued message\rDraft");
                    $stage = 1;
                } elseif ($stage === 1 && str_contains($screen, 'Choose something')) {
                    self::assertSame(['Start'], $received->inputs);
                    self::assertStringContainsString('↳ Queued message', $screen);
                    $terminal->simulateInput("\r");
                    $stage = 2;
                } elseif ($stage === 2 && str_contains($screen, 'Queued turn finished') && str_contains($screen, 'Ready')) {
                    self::assertCount(3, $received->inputs);
                    self::assertInstanceOf(CommandInput::class, $received->inputs[1]);
                    self::assertSame('/choose', $received->inputs[1]->identifier);
                    self::assertSame('chosen value', $received->inputs[1]->value);
                    self::assertSame('Queued message', $received->inputs[2]);
                    self::assertStringContainsString('❯ Draft', $screen);
                    return true;
                }
                return false;
            },
            function (MainView $view) use ($received): void {
                $view->onInput(function (string|CommandInput $input) use ($view, $received): void {
                    $received->inputs[] = $input;
                    EventLoop::queue(function () use ($view, $input): void {
                        if ($input === 'Start') {
                            $view->showSelection(new SelectionRequest('/choose', 'Choose something', [
                                new SelectionOption('chosen value', 'Choice'),
                            ]));
                        }
                        if ($input === 'Queued message') {
                            $view->appendResponse('Queued turn finished');
                        }
                        $view->finishTurn();
                    });
                });
            },
        );
        self::assertSame(2, $stage);
    }

    public function testCancellingSelectionSubmitsNothingAndResumesQueuedMessages(): void
    {
        $received = [];
        $stage = 0;
        $this->runApplication(
            Agent::make()->setAiProvider(new FakeAIProvider()),
            function (VirtualTerminal $terminal, string $screen) use (&$stage, &$received): bool {
                if ($stage === 0) {
                    $terminal->simulateInput("Start\rQueued message\rDraft");
                    $stage = 1;
                } elseif ($stage === 1 && str_contains($screen, 'Choose something')) {
                    self::assertSame(['Start'], $received);
                    $terminal->simulateInput("\x1b");
                    $stage = 2;
                } elseif ($stage === 2 && str_contains($screen, 'Ready') && str_contains($screen, '❯ Draft')) {
                    self::assertSame(['Start', 'Queued message'], $received);
                    self::assertStringNotContainsString('Choose something', $screen);
                    return true;
                }
                return false;
            },
            function (MainView $view) use (&$received): void {
                $view->onInput(function (string|CommandInput $input) use ($view, &$received): void {
                    $received[] = $input;
                    EventLoop::queue(function () use ($view, $input): void {
                        if ($input === 'Start') {
                            $view->showSelection(new SelectionRequest('/choose', 'Choose something', [
                                new SelectionOption('chosen value', 'Choice'),
                            ]));
                        }
                        $view->finishTurn();
                    });
                });
            },
        );
        self::assertSame(2, $stage);
    }

    public function testQuittingWithASelectionOpenDiscardsPendingMessages(): void
    {
        $received = [];
        $stage = 0;
        $this->runApplication(
            Agent::make()->setAiProvider(new FakeAIProvider()),
            function (VirtualTerminal $terminal, string $screen) use (&$stage): bool {
                if ($stage === 0) {
                    $terminal->simulateInput("Start\rQueued message\r");
                    $stage = 1;
                }
                return str_contains($screen, 'Choose something');
            },
            function (MainView $view) use (&$received): void {
                $view->onInput(function (string|CommandInput $input) use ($view, &$received): void {
                    $received[] = $input;
                    EventLoop::queue(function () use ($view): void {
                        $view->showSelection(new SelectionRequest('/choose', 'Choose something', [
                            new SelectionOption('value', 'Choice'),
                        ]));
                        $view->finishTurn();
                    });
                });
            },
        );
        self::assertSame(['Start'], $received);
    }

    public function testCommandMenuFiltersCompletesAndExecutesRegisteredCommands(): void
    {
        $provider = new FakeAIProvider();
        $conversation = $this->conversation(Agent::make()->setAiProvider($provider));
        $command = new class implements CommandInterface {
            public function name(): string
            {
                return '/theme';
            }

            public function describe(): string
            {
                return 'Change the theme';
            }

            public function run(CommandContext $context, string $value): void
            {
                $context->notify('Theme selected: ' . $value);
            }
        };
        $conversation->setCommands(new Commands(new HelpCommand(), $command));
        $stage = 0;
        $screen = $this->runApplication($conversation, function (
            VirtualTerminal $terminal,
            string $screen,
        ) use (&$stage): bool {
            if ($stage === 0) {
                $terminal->simulateInput('/');
                $stage = 1;
            } elseif ($stage === 1 && str_contains($screen, 'Change the theme')) {
                self::assertStringContainsString('❯ /help', $screen);
                self::assertStringContainsString('Lists what can be typed here.', $screen);
                $terminal->simulateInput("\x1b[B");
                $stage = 2;
            } elseif ($stage === 2 && str_contains($screen, '❯ /theme')) {
                $terminal->simulateInput("\t");
                $stage = 3;
            } elseif ($stage === 3 && str_contains($screen, '❯ /theme')
                && !str_contains($screen, 'Change the theme')) {
                $terminal->simulateInput("dark\r");
                $stage = 4;
            } elseif ($stage === 4 && str_contains($screen, 'Theme selected: dark')
                && str_contains($screen, 'Ready')) {
                $terminal->simulateInput('/he');
                $stage = 5;
            } elseif ($stage === 5 && str_contains($screen, 'Lists what can be typed here.')) {
                self::assertStringNotContainsString('Change the theme', $screen);
                $terminal->simulateInput("\r");
                $stage = 6;
            } elseif ($stage === 6 && str_contains($screen, '/help — Lists what can be typed here.')
                && str_contains($screen, 'Ready')) {
                return true;
            }
            return false;
        });
        self::assertSame(6, $stage);
        self::assertStringContainsString('/theme — Change the theme', $screen);
        $provider->assertNothingSent();
    }

    public function testEscapeStopsTheProviderAndPreservesThePartialResponse(): void
    {
        $events = [
            ['type' => 'response.output_item.added', 'item' => ['type' => 'message', 'id' => 'msg_1', 'content' => []]],
            ['type' => 'response.output_text.delta', 'item_id' => 'msg_1', 'delta' => 'Partial answer'],
            ['type' => 'response.output_text.delta', 'item_id' => 'msg_1', 'delta' => ' unwanted continuation'],
            ['type' => 'response.completed', 'response' => []],
        ];
        $lines = array_map(static fn(array $event): string => 'data: ' . json_encode($event, JSON_THROW_ON_ERROR) . "\n", $events);
        $reader = new ArrayIterator($lines);
        $closed = false;
        $native = $this->createStub(StreamInterface::class);
        $native->method('eof')->willReturnCallback(static fn(): bool => !$reader->valid());
        $native->method('readLine')->willReturnCallback(static function () use ($reader): string {
            if (!$reader->valid()) {
                return '';
            }
            $line = $reader->current();
            $reader->next();
            return $line;
        });
        $native->method('close')->willReturnCallback(static function () use (&$closed): void {
            $closed = true;
        });
        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('stream')->willReturn($native);
        $signal = new StopSignal(new InMemoryStorage(), 'response');
        $callback = $signal->stopCallback(onPoll: static function (): void {
            // Let the terminal deliver Esc while the provider is streaming.
            $suspension = EventLoop::getSuspension();
            EventLoop::delay(0.003, static fn() => $suspension->resume());
            $suspension->suspend();
        }, pollInterval: 0);
        $provider = new OpenAIResponses(
            key: 'test-key',
            model: 'test-model',
            httpClient: new StoppableHttpClient($httpClient, $callback),
        );
        $conversation = $this->conversation(Agent::make()->setAiProvider($provider));
        $conversation->setStopSignal($signal);
        $stage = 0;

        $screen = $this->runApplication($conversation, function (VirtualTerminal $terminal, string $screen) use (&$stage): bool {
            if ($stage === 0) {
                $terminal->simulateInput("Question\r");
                $stage = 1;
            } elseif ($stage === 1 && str_contains($screen, 'Partial answer')) {
                $terminal->simulateInput("\x1b");
                $stage = 2;
            } elseif ($stage === 2 && str_contains($screen, 'Ready') && str_contains($screen, 'Response stopped.')) {
                return true;
            }
            return false;
        });

        self::assertSame(2, $stage);
        self::assertTrue($closed);
        self::assertTrue($conversation->responseWasStopped());
        self::assertStringNotContainsString('unwanted continuation', $screen);
        $messages = $conversation->session()->getMessages();
        self::assertCount(2, $messages);
        self::assertSame('Partial answer', $messages[1]->getContent());
        self::assertInstanceOf(AssistantMessage::class, $messages[1]);
        self::assertSame(StoppableHttpClient::STOP_REASON, $messages[1]->stopReason());
    }

    public function testEscapeDismissesCommandMenuBeforeInterruptingATurn(): void
    {
        $stage = 0;
        $escapes = 0;
        $this->runApplication(
            Agent::make()->setAiProvider(new FakeAIProvider()),
            function (VirtualTerminal $terminal, string $screen) use (&$stage): bool {
                if ($stage === 0) {
                    $terminal->simulateInput("Question\r/");
                    $stage = 1;
                } elseif ($stage === 1 && str_contains($screen, 'Lists what can be typed here.')) {
                    $terminal->simulateInput("\x1b");
                    $stage = 2;
                } elseif ($stage === 2 && !str_contains($screen, 'Lists what can be typed here.')) {
                    self::assertStringContainsString('Working', $screen);
                    self::assertStringContainsString('❯ /', $screen);
                    $terminal->simulateInput("\x1b");
                    $stage = 3;
                } elseif ($stage === 3 && str_contains($screen, 'Ready')) {
                    return true;
                }
                return false;
            },
            function (MainView $view) use (&$escapes): void {
                $view->onCommands(static fn(): array => [new HelpCommand()]);
                $view->onInput(static function (string|CommandInput $input): void {});
                $view->onEscape(function () use ($view, &$escapes): void {
                    ++$escapes;
                    $view->finishTurn();
                });
            },
        );
        self::assertSame(1, $escapes);
        self::assertSame(3, $stage);
    }

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
            $view->onInput(function (string|CommandInput $input) use ($view): void {
                EventLoop::delay(0.3, $view->finishTurn(...));
            });
        });
    }

    public function testQueuedMessagesRemainVisibleUntilTheirTurnStarts(): void
    {
        $stage = 0;
        $received = [];
        $view = null;
        $this->runApplication(Agent::make()->setAiProvider(new FakeAIProvider()), function (
            VirtualTerminal $terminal,
            string $screen,
        ) use (&$stage, &$received, &$view): bool {
            self::assertInstanceOf(MainView::class, $view);
            if ($stage === 0) {
                $terminal->simulateInput("First input\r");
                $stage = 1;
            } elseif ($stage === 1 && str_contains($screen, 'Working')) {
                $terminal->simulateInput("Second input\x1b[13;2uExtra line\rThird input\rUnsubmitted draft");
                $stage = 2;
            } elseif ($stage === 2 && str_contains($screen, 'Unsubmitted draft')) {
                self::assertStringContainsString('• Messages queued for the next turns (2)', $screen);
                self::assertStringContainsString('↳ Second input Extra line', $screen);
                self::assertStringContainsString('↳ Third input', $screen);
                self::assertLessThan(strpos($screen, '• Messages queued'), strpos($screen, 'Working'));
                self::assertLessThan(strpos($screen, '❯ Unsubmitted draft'), strpos($screen, '↳ Third input'));
                self::assertSame(['First input'], $received);
                $view->appendResponse('First response');
                $view->finishTurn();
                $stage = 3;
            } elseif ($stage === 3 && str_contains($screen, '❯ Second input')) {
                self::assertStringContainsString('• Messages queued for the next turns (1)', $screen);
                self::assertStringContainsString('↳ Third input', $screen);
                self::assertStringNotContainsString('↳ Second input', $screen);
                $view->appendResponse('Second response');
                $view->finishTurn();
                $stage = 4;
            } elseif ($stage === 4 && str_contains($screen, '❯ Third input')) {
                self::assertStringNotContainsString('• Messages queued', $screen);
                $view->appendResponse('Third response');
                $view->finishTurn();
                $stage = 5;
            } elseif ($stage === 5 && str_contains($screen, 'Ready')) {
                self::assertStringNotContainsString('• Messages queued', $screen);
                self::assertStringContainsString('❯ Unsubmitted draft', $screen);
                self::assertSame(['First input', "Second input\nExtra line", 'Third input'], $received);
                return true;
            }
            return false;
        }, function (MainView $host) use (&$view, &$received): void {
            $view = $host;
            $host->onInput(function (string|CommandInput $input) use (&$received): void {
                $received[] = $input;
            });
        });
        self::assertSame(5, $stage);
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
            $submit = str_contains($screen, 'Working') ? 'queue' : 'send';
            self::assertStringStartsWith("Enter {$submit}", $lines[39], $screen);
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
            self::assertStringNotContainsString(
                '• Messages queued',
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
        self::assertSame($conversation->session()->messageNamespace(), $conversation->agent()->getThreadId());
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
                $host->onInput(function (string|CommandInput $input) use ($host, &$received): void {
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
            $submit = str_contains($screen, 'Working') ? 'queue' : 'send';
            self::assertSame("Enter {$submit} · Shift+Enter newline · Ctrl+C exit", trim($lines[39]));
            self::assertSame('❯', trim($lines[37]));
            self::assertStringStartsWith('❯', $lines[37]);
            if ($stage === 0) {
                self::assertSame('✻ Ready', rtrim($lines[35]));
                $terminal->simulateInput("New question\r");
                $stage = 1;
            } elseif ($stage === 1 && str_contains($screen, 'Working')) {
                self::assertMatchesRegularExpression('/^[⠋⠙⠹⠸⠼⠴⠦⠧⠇⠏] Working \(0s\)$/u', rtrim($lines[35]));
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
                $submit = str_contains($screen, 'Working') ? 'queue' : 'send';
                self::assertSame("Enter {$submit} · Shift+Enter newline · Ctrl+C exit", trim($lines[17]));
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
        $nativeMessages = new InMemoryMessageStore();
        $messages = $agent->getThreadId() === null ? [] : $agent->getChatHistory()->getMessages();
        $session = (new SessionStore(new InMemoryStorage(), $nativeMessages, 'test-user'))->create();
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
     * @param Closure(Tui): void|null $configureHost
     */
    private function runApplication(
        Agent|Conversation $agent,
        Closure $step,
        ?Closure $configure = null,
        ?Closure $verify = null,
        ?Closure $configureHost = null,
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
        $host = new Tui($conversation);
        $configureHost?->__invoke($host);
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
