<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Tui;

use Closure;
use InvalidArgumentException;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronInteraction\Command\ClearCommand;
use NeuronInteraction\Command\CommandContext;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\ResumeCommand;
use NeuronInteraction\Command\SelectionOption;
use NeuronInteraction\Command\SelectionRequest;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\InputHistory\InputHistory;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\FileStorage;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteraction\Storage\StorageInterface;
use NeuronInteraction\Storage\StoredDocument;
use NeuronTui\Tests\Command\CommandObservation;
use NeuronTui\Tests\History\SeededHistory;
use NeuronTui\Tests\History\StoredConversation;
use NeuronTui\Tui;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

use function array_map;
use function bin2hex;
use function count;
use function glob;
use function is_dir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

final class SessionCompositionTest extends TestCase
{
    public function testConfigurationCreatesNoSessionAndRunCreatesOneInTheSuppliedStore(): void
    {
        $creates = 0;
        $document = new StoredDocument('created', [], ['userId' => 'alice']);
        $storage = $this->createMock(StorageInterface::class);
        $storage->expects(self::once())->method('create')->willReturnCallback(
            static function () use (&$creates, $document): StoredDocument {
                ++$creates;
                return $document;
            },
        );
        $storage->method('read')->willReturn($document);
        $store = new SessionStore($storage, 'alice');
        $terminal = new VirtualTerminal();
        $command = $this->commandThat(static function (CommandContext $context): void {
            self::assertSame('alice', $context->session()->getUserId());
            self::assertSame([], $context->session()->getMessages());
            $context->requestExit();
        });
        $tui = Tui::make(new Agent())
            ->setSessionStore($store)
            ->setCommands(new Commands($command))
            ->setTerminal($terminal);
        EventLoop::queue(static fn() => $terminal->simulateInput("/inspect\r"));
        self::assertSame(0, $creates, 'Session creation must wait until run().');
        $tui->run();
    }

    public function testSelectingAnInitialSessionCreatesNoTemporarySession(): void
    {
        foreach ([false, true] as $sessionFirst) {
            $memory = new InMemoryStorage();
            $storage = $this->createMock(StorageInterface::class);
            $storage->expects(self::once())->method('create')->willReturnCallback($memory->create(...));
            $storage->method('read')->willReturnCallback($memory->read(...));
            $storage->method('write')->willReturnCallback($memory->write(...));
            $store = new SessionStore($storage, 'alice');
            $session = $store->create();
            StoredConversation::turn($store, $session, new UserMessage('Existing conversation'));
            $terminal = new VirtualTerminal();
            $tui = Tui::make(new Agent())
                ->setTerminal($terminal);
            if ($sessionFirst) {
                $tui
                    ->setSession($session)
                    ->setSessionStore($store);
            } else {
                $tui
                    ->setSessionStore($store)
                    ->setSession($session);
            }

            EventLoop::delay(0.05, static fn() => $terminal->simulateInput("\x03"));
            $tui->run();

            self::assertSame('Existing conversation', $session->getMessages()[0]->getContent());
            self::assertStringContainsString('Existing conversation', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
        }
    }

    public function testStartupAcceptsAnExternalSessionWithTheDefaultStore(): void
    {
        $store = new SessionStore(new InMemoryStorage(), 'alice');
        $session = $store->create();
        $terminal = new VirtualTerminal();
        $inspect = $this->commandThat(static function (CommandContext $context) use ($session): void {
            self::assertSame($session, $context->session());
            $context->requestExit();
        });
        EventLoop::queue(static fn() => $terminal->simulateInput("/inspect\r"));
        $tui = Tui::make(new Agent())
            ->setSession($session)
            ->setTerminal($terminal)
            ->setCommands(new Commands($inspect));

        $tui->run();
    }

    public function testManagedConversationsAndLaterTurnsCanBeClearedAndResumed(): void
    {
        foreach (['default', 'supplied', 'preselected'] as $composition) {
            $store = new SessionStore(new InMemoryStorage(), $composition === 'default' ? 'local' : 'test-user');
            $session = $composition === 'preselected' ? $store->create() : null;
            if ($session !== null) {
                StoredConversation::turn($store, $session, new UserMessage('Initial subject'), new AssistantMessage('Initial answer'));
            }
            $agent = (new Agent())->setAiProvider(new FakeAIProvider(new AssistantMessage('Generated continuation')));
            $terminal = new VirtualTerminal(rows: 40);
            $currentStore = null;
            $inspect = $this->commandThat(static function (CommandContext $context) use (&$currentStore, $store): void {
                $currentStore = $store;
            });
            $beforeClear = null;
            $originalKey = null;
            $afterClear = null;
            $observation = new CommandObservation();
            $tui = Tui::make($agent);
            $tui->setSessionStore($store);
            if ($session !== null) {
                $tui->setSession($session);
            }
            $tui
                ->setTerminal($terminal)
                ->setCommands($observation->wrap(new Commands(new ClearCommand($store), new ResumeCommand($store), $inspect)));
            EventLoop::queue(static fn() => $terminal->simulateInput("Later question\r"));
            EventLoop::delay(0.12, static fn() => $terminal->simulateInput("/inspect\r"));
            EventLoop::delay(0.15, static function () use ($observation, $terminal, &$beforeClear, &$originalKey): void {
                $beforeClear = $observation->agent()->getChatHistory()->getMessages();
                $originalKey = $observation->agent()->getThreadId();
                $terminal->simulateInput("/clear\r");
            });
            EventLoop::delay(0.19, static function () use ($observation, $terminal, &$afterClear): void {
                $afterClear = $observation->agent()->getChatHistory()->getMessages();
                $terminal->simulateInput("/resume\r");
            });
            EventLoop::delay(0.23, static fn() => $terminal->simulateInput("\r"));
            EventLoop::delay(0.29, static fn() => $terminal->simulateInput("\x03"));

            $tui->run();

            self::assertIsArray($beforeClear);
            self::assertCount($composition === 'preselected' ? 4 : 2, $beforeClear);
            self::assertSame([], $afterClear);
            self::assertEquals($beforeClear, $observation->agent()->getChatHistory()->getMessages());
            self::assertSame($originalKey, $observation->agent()->getThreadId());
            self::assertIsString($originalKey);
            self::assertInstanceOf(SessionStore::class, $currentStore);
            $stored = $currentStore->get($originalKey);
            self::assertNotNull($stored);
            self::assertEquals($beforeClear, $stored->getMessages());
        }
    }

    public function testStartupDoesNotAutomaticallySelectAStoredSession(): void
    {
        $storage = new InMemoryStorage();
        $store = new SessionStore($storage, 'test-user');
        $earlier = $store->create();
        StoredConversation::turn($store, $earlier, new UserMessage('Stored subject'));
        $terminal = new VirtualTerminal();
        $inspect = $this->commandThat(static function (CommandContext $context) use ($earlier): void {
            self::assertNotSame($earlier->getKey(), $context->session()->getKey());
            self::assertSame([], $context->session()->getMessages());
            $context->requestExit();
        });
        $tui = Tui::make(new Agent())
            ->setSessionStore($store)
            ->setCommands(new Commands($inspect));
        $tui->setTerminal($terminal);
        EventLoop::queue(static fn() => $terminal->simulateInput("/inspect\r"));

        $tui->run();

        self::assertCount(1, $store->list());
        self::assertStringNotContainsString('Stored subject', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testOmittedCommandsStayEmptyWithIndependentlySuppliedState(): void
    {
        foreach ([false, true] as $supplySessionStore) {
            foreach ([false, true] as $supplyInputs) {
                $terminal = new VirtualTerminal();
                $inputs = $supplyInputs ? new InputHistory(new InMemoryStorage()) : null;
                EventLoop::queue(static fn() => $terminal->simulateInput("/help\r"));
                EventLoop::delay(0.06, static fn() => $terminal->simulateInput("\x03"));

                $tui = Tui::make((new Agent())->setThreadId('test-thread'))
                    ->setTerminal($terminal);
                if ($supplySessionStore) {
                    $tui->setSessionStore(new SessionStore(new InMemoryStorage(), 'test-user'));
                }
                if ($inputs !== null) {
                    $tui->setInputHistory($inputs);
                }
                $tui->run();

                self::assertStringContainsString('Unknown Command: /help', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                if ($inputs !== null) {
                    self::assertSame(['/help'], array_map(static fn(UserMessage $message): ?string => $message->getContent(), $inputs->entries()));
                }
            }
        }
    }

    public function testSuppliedAndDefaultSessionsKeepTheirStateAcrossCommands(): void
    {
        $defaultSessions = [];
        foreach ([false, true, false] as $supplySessionStore) {
            foreach ([false, true] as $supplyInputs) {
                $sessionStore = $supplySessionStore ? new SessionStore(new InMemoryStorage(), 'test-user') : null;
                $inputs = $supplyInputs ? new InputHistory(new InMemoryStorage()) : null;
                $received = [];
                $command = $this->commandThat(
                    static function (CommandContext $context) use (&$received): void {
                        $received[] = [$context->commands(), $context->session()];
                        if (count($received) === 1) {
                            $context->session()->setMetadata('kept', 'Kept by this module');
                        } else {
                            self::assertSame('Kept by this module', $context->session()->getMetadata()['kept']);
                        }
                    },
                );
                $commands = new Commands($command);
                $terminal = new VirtualTerminal();
                $tui = Tui::make(new Agent())
                    ->setTerminal($terminal)
                    ->setCommands($commands);
                if ($sessionStore !== null) {
                    $tui->setSessionStore($sessionStore);
                }
                if ($inputs !== null) {
                    $tui->setInputHistory($inputs);
                }
                EventLoop::queue(static fn() => $terminal->simulateInput("/inspect\r"));
                EventLoop::delay(0.04, static fn() => $terminal->simulateInput("\x1b[A\r"));
                EventLoop::delay(0.08, static fn() => $terminal->simulateInput("\x1b[A"));
                EventLoop::delay(0.12, static fn() => $terminal->simulateInput("\x03"));

                $tui->run();

                self::assertCount(2, $received);
                self::assertSame($commands->all(), $received[0][0]);
                self::assertSame($received[0], $received[1]);
                if ($sessionStore !== null) {
                    self::assertSame('Kept by this module', $sessionStore->get($received[0][1]->getKey())?->getMetadata()['kept']);
                } else {
                    $defaultSessions[] = $received[0][1];
                }
                if ($inputs !== null) {
                    self::assertSame(['/inspect'], array_map(static fn(UserMessage $message): ?string => $message->getContent(), $inputs->entries()));
                    self::assertTrue($inputs->isNavigating());
                }
            }
        }
        self::assertCount(4, $defaultSessions);
        self::assertNotSame($defaultSessions[0], $defaultSessions[1]);
        self::assertNotSame($defaultSessions[0], $defaultSessions[2]);
    }

    public function testConfigurationStoreSurvivesSelectionAndIsScopedToTheTui(): void
    {
        $defaults = [];
        foreach ([false, true, false] as $supplyStore) {
            $storage = new InMemoryStorage();
            $store = $supplyStore ? new ConfigurationStore($storage, 'test-user') : null;
            $received = [];
            $command = $this->commandThat(
                static function (CommandContext $context) use (&$received): void {
                    $configurationStore = $context->configurationStore();
                    $received[] = $configurationStore;
                    if (count($received) === 1) {
                        self::assertNull($configurationStore->read('model'));
                        $configurationStore->write('model', 'saved-model');
                        $context->requestSelection(new SelectionRequest('/inspect', 'Choose', [
                            new SelectionOption('selected-model', 'Selected model'),
                        ]));

                        return;
                    }

                    self::assertSame('saved-model', $configurationStore->read('model'));
                    $configurationStore->write('model', 'selected-model');
                    $context->requestExit();
                },
            );
            $terminal = new VirtualTerminal();
            EventLoop::queue(static fn() => $terminal->simulateInput("/inspect\r"));
            EventLoop::delay(0.04, static fn() => $terminal->simulateInput("\r"));
            $timeout = EventLoop::delay(0.15, static fn() => $terminal->simulateInput("\x03"));

            $tui = Tui::make((new Agent())->setThreadId('test-thread'))
                ->setSessionStore(new SessionStore(new InMemoryStorage(), 'local'))
                ->setTerminal($terminal)
                ->setCommands(new Commands($command));
            if ($store !== null) {
                $tui->setConfigurationStore($store);
            }
            $tui->run();
            EventLoop::cancel($timeout);

            self::assertCount(2, $received);
            self::assertSame($received[0], $received[1]);
            if ($store !== null) {
                self::assertSame($store, $received[0]);
                self::assertSame('selected-model', (new ConfigurationStore($storage, 'test-user'))->read('model'));
                self::assertSame([], (new ConfigurationStore($storage, 'other-user'))->entries());
            } else {
                self::assertSame('selected-model', $received[0]->read('model'));
                $defaults[] = $received[0];
            }
        }
        self::assertNotSame($defaults[0], $defaults[1]);
        $defaults[0]->write('model', 'changed');
        self::assertSame('selected-model', $defaults[1]->read('model'));
    }

    public function testExistingMessagesRequireAnExplicitSession(): void
    {
        $history = new SeededHistory();
        $history->addMessage(new UserMessage('External conversation'));
        $agent = $history->bindToAgent(new Agent());
        $store = new SessionStore(new InMemoryStorage(), 'test-user');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An Agent with existing messages requires an explicit Session.');

        Tui::make($agent)
            ->setSessionStore($store)
            ->setTerminal(new VirtualTerminal())
            ->run();
    }

    public function testExplicitSessionDeterminesTheConversationWithoutImportingAgentMessages(): void
    {
        $store = new SessionStore(new InMemoryStorage(), 'test-user');
        $session = $store->create();
        StoredConversation::turn($store, $session, new UserMessage('Selected conversation'));
        $history = new SeededHistory();
        $history->addMessage(new UserMessage('External conversation'));
        $agent = $history->bindToAgent(new Agent());
        $terminal = new VirtualTerminal();
        $tui = Tui::make($agent)
            ->setSessionStore($store)
            ->setSession($session);
        $observedSession = null;
        $inspect = $this->commandThat(static function (CommandContext $context) use (&$observedSession): void {
            $observedSession = $context->session();
            $context->requestExit();
        });
        $tui
            ->setTerminal($terminal)
            ->setCommands(new Commands($inspect));
        EventLoop::delay(0.03, static fn() => $terminal->simulateInput("/inspect\r"));
        EventLoop::delay(0.05, static fn() => $terminal->simulateInput("\x03"));

        $tui->run();

        self::assertNotNull($observedSession);
        self::assertSame($session->getKey(), $observedSession->getKey());
        self::assertEquals($session->getMessages(), $observedSession->getMessages());
        self::assertSame($history->getThreadId(), $agent->getThreadId());
        self::assertCount(2, $session->getMessages());
        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString('Selected conversation', $display);
        self::assertStringNotContainsString('External conversation', $display);
    }

    public function testStartupAcceptsASessionOwnedByAnotherUser(): void
    {
        $storage = new InMemoryStorage();
        $foreign = (new SessionStore($storage, 'bob'))->create();

        $terminal = new VirtualTerminal();
        $inspect = $this->commandThat(static function (CommandContext $context) use ($foreign): void {
            self::assertSame($foreign, $context->session());
            $context->requestExit();
        });
        EventLoop::queue(static fn() => $terminal->simulateInput("/inspect\r"));
        Tui::make(new Agent())
            ->setSessionStore(new SessionStore($storage, 'alice'))
            ->setSession($foreign)
            ->setTerminal($terminal)
            ->setCommands(new Commands($inspect))
            ->run();
    }

    public function testStartupAcceptsASessionAbsentFromTheSuppliedStore(): void
    {
        $session = (new SessionStore(new InMemoryStorage(), 'alice'))->create();

        $terminal = new VirtualTerminal();
        $inspect = $this->commandThat(static function (CommandContext $context) use ($session): void {
            self::assertSame($session, $context->session());
            $context->requestExit();
        });
        EventLoop::queue(static fn() => $terminal->simulateInput("/inspect\r"));
        Tui::make(new Agent())
            ->setSessionStore(new SessionStore(new InMemoryStorage(), 'alice'))
            ->setSession($session)
            ->setTerminal($terminal)
            ->setCommands(new Commands($inspect))
            ->run();
    }

    public function testDefaultStoreUsesLocalOwner(): void
    {
        $terminal = new VirtualTerminal();
        $owner = null;
        $command = $this->commandThat(
            static function (CommandContext $context) use (&$owner): void {
                $owner = $context->session()->getUserId();
                $context->requestExit();
            },
        );
        EventLoop::queue(static fn() => $terminal->simulateInput("/inspect\r"));

        Tui::make((new Agent())->setThreadId('test-thread'))
            ->setTerminal($terminal)
            ->setCommands(new Commands($command))
            ->run();

        self::assertSame('local', $owner);
    }

    public function testSuppliedStoreKeepsItsOwner(): void
    {
        $sessionStore = new SessionStore(new InMemoryStorage(), 'store-owner');
        $terminal = new VirtualTerminal();
        $received = null;
        $owner = null;
        $command = $this->commandThat(
            static function (CommandContext $context) use (&$received, &$owner): void {
                $received = $context->session();
                $owner = $received->getUserId();
                $context->requestExit();
            },
        );
        EventLoop::queue(static fn() => $terminal->simulateInput("/inspect\r"));

        Tui::make((new Agent())->setThreadId('test-thread'))
            ->setSessionStore($sessionStore)
            ->setTerminal($terminal)
            ->setCommands(new Commands($command))
            ->run();

        self::assertNotNull($received);
        self::assertNotNull($sessionStore->get($received->getKey()));
        self::assertSame('store-owner', $owner);
    }

    public function testClearAndResumePreserveOnlyTheCurrentUsersPersistedConversation(): void
    {
        $directory = sys_get_temp_dir() . '/neuron-tui-scoped-' . bin2hex(random_bytes(6));

        try {
            $storage = new FileStorage($directory);
            $sessionStore = new SessionStore($storage, 'alice');
            $foreign = (new SessionStore($storage, 'bob'))->create();
            StoredConversation::turn(new SessionStore($storage, 'bob'), $foreign, new UserMessage('Private Bob subject'));
            $initial = $sessionStore->create();
            $agent = (new Agent())->setThreadId('test-thread');
            $agent = ($initial)->bindToAgent($agent);
            $provider = new FakeAIProvider(new AssistantMessage('Persisted Alice answer'));
            $agent->setAiProvider($provider);
            $terminal = new VirtualTerminal(rows: 30);
            $cleared = null;
            $picker = null;
            $observation = new CommandObservation();
            $tui = Tui::make($agent)
                ->setSessionStore($sessionStore)
                ->setSession($initial)
                ->setTerminal($terminal)
                ->setCommands($observation->wrap(new Commands(new ClearCommand($sessionStore), new ResumeCommand($sessionStore))));
            EventLoop::queue(static fn() => $terminal->simulateInput("Alice subject\r"));
            EventLoop::delay(0.15, static fn() => $terminal->simulateInput("/clear\r"));
            EventLoop::delay(0.19, static function () use ($observation, $terminal, &$cleared): void {
                $cleared = $observation->agent()->getChatHistory();
                $terminal->simulateInput("/resume\r");
            });
            EventLoop::delay(0.23, static function () use ($terminal, &$picker): void {
                $picker = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                $terminal->simulateInput("\r");
            });
            EventLoop::delay(0.29, static fn() => $terminal->simulateInput("\x03"));

            $tui->run();

            self::assertInstanceOf(ChatHistory::class, $cleared);
            $clearedSession = $sessionStore->get($cleared->getThreadId());
            self::assertNotNull($clearedSession);
            self::assertSame('alice', $clearedSession->getUserId());
            self::assertNotSame($initial->getKey(), $cleared->getThreadId());
            self::assertSame([], $cleared->getMessages());
            self::assertIsString($picker);
            self::assertStringContainsString('Alice subject', $picker);
            self::assertStringNotContainsString('Private Bob subject', $picker);
            self::assertNotNull($sessionStore->get($observation->agent()->getChatHistory()->getThreadId()));
            self::assertSame($initial->getKey(), $observation->agent()->getChatHistory()->getThreadId());
            $reopened = (new SessionStore(new FileStorage($directory), 'alice'))->get($initial->getKey());
            self::assertNotNull($reopened);
            self::assertCount(2, $reopened->getMessages());
            self::assertSame('Persisted Alice answer', $reopened->getMessages()[1]->getContent());
            self::assertEquals($reopened->getMessages(), $observation->agent()->getChatHistory()->getMessages());
            self::assertNull($sessionStore->get($foreign->getKey()));
            self::assertNotNull((new SessionStore(new FileStorage($directory), 'bob'))->get($foreign->getKey()));
            $provider->assertCallCount(1);
        } finally {
            foreach (glob($directory . '/sessions/*') ?: [] as $path) {
                unlink($path);
            }
            if (is_dir($directory . '/sessions')) {
                rmdir($directory . '/sessions');
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function testResumeHandlesASelectionDeletedWhileThePickerWasOpen(): void
    {
        $sessionStore = new SessionStore(new InMemoryStorage(), 'alice');
        $earlier = $sessionStore->create();
        $earlier->setTitle('Removed target');
        StoredConversation::turn($sessionStore, $earlier, new UserMessage('Session removed during selection'));
        $initial = $sessionStore->create();
        StoredConversation::turn($sessionStore, $initial, new UserMessage('Current conversation'));
        $agent = (new Agent())->setThreadId('test-thread');
        $agent = ($initial)->bindToAgent($agent);
        $terminal = new VirtualTerminal();
        $observation = new CommandObservation();
        $tui = Tui::make($agent)
            ->setSessionStore($sessionStore)
            ->setSession($initial)
            ->setTerminal($terminal)
            ->setCommands($observation->wrap(new Commands(new ResumeCommand($sessionStore))));
        EventLoop::queue(static fn() => $terminal->simulateInput("/resume\r"));
        EventLoop::delay(0.03, static fn() => $terminal->simulateInput("\x1b[B"));
        EventLoop::delay(0.05, static function () use ($sessionStore, $earlier, $terminal): void {
            $sessionStore->delete($earlier->getKey());
            $terminal->simulateInput("\r");
        });
        EventLoop::delay(0.1, static fn() => $terminal->simulateInput("\x03"));

        $tui->run();

        self::assertSame($initial->getKey(), $observation->agent()->getChatHistory()->getThreadId());
        self::assertStringContainsString(
            'No Session is named by that key.',
            AnsiUtils::stripAnsiCodes($terminal->getOutput()),
        );
        self::assertCount(1, $sessionStore->list());
    }

    /**
     * @param Closure(CommandContext): void $run
     */
    private function commandThat(Closure $run): CommandInterface
    {
        return new class ($run) implements CommandInterface {
            /** @param Closure(CommandContext): void $run */
            public function __construct(private readonly Closure $run) {}

            public function name(): string
            {
                return '/inspect';
            }

            public function describe(): string
            {
                return 'Inspects the runtime composition.';
            }

            public function run(CommandContext $context, string $value): void
            {
                ($this->run)($context);
            }
        };
    }
}
