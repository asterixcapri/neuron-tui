<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Tui;

use Closure;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronInteraction\Command\ClearCommand;
use NeuronInteraction\Command\CommandAdapterInterface;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\ResumeCommand;
use NeuronInteraction\Command\Selection;
use NeuronInteraction\Command\SelectionOption;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\InputHistory\InputHistory;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\FileStorage;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronTui\Tests\History\SeededHistory;
use NeuronTui\Tests\History\SessionHistory;
use NeuronTui\Tui;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class SessionCompositionTest extends TestCase
{
    public function testManagedConversationsAndLaterTurnsCanBeClearedAndResumed(): void
    {
        foreach (['default', 'preselected'] as $composition) {
            $sessionStore = $composition === 'default' ? null : new SessionStore(new InMemoryStorage(), 'test-user');
            $initial = $sessionStore !== null
                ? $sessionStore->create()
                : new SeededHistory();
            SessionHistory::of($initial)->addMessage(new UserMessage('Initial subject'));
            SessionHistory::of($initial)->addMessage(new AssistantMessage('Initial answer'));
            $selectedKey = null;
            if ($sessionStore !== null) {
                $selectedKey = $sessionStore->summaries()[0]->key;
                $initial = $sessionStore->read($selectedKey);
                self::assertNotNull($initial);
            }
            $initialMessages = SessionHistory::of($initial)->getMessages();
            $agent = (new Agent())->setThreadId('test-thread');
            $agent = ($initial)->bindTo($agent);
            $agent->setAiProvider(new FakeAIProvider(new AssistantMessage('Generated continuation')));
            $terminal = new VirtualTerminal(rows: 40);
            $startup = null;
            $beforeClear = null;
            $afterClear = null;
            $tui = Tui::make($agent, $terminal, (new Commands())->addCommand([new ClearCommand(), new ResumeCommand()]), $sessionStore);
            EventLoop::queue(static function () use ($tui, &$startup): void {
                $startup = $tui->agent()->getChatHistory()->getMessages();
            });
            if ($sessionStore === null) {
                // The default collection starts managing History only after /clear.
                EventLoop::delay(0.01, static fn () => $terminal->simulateInput("/clear\r"));
            }
            EventLoop::delay(0.03, static fn () => $terminal->simulateInput("Later question\r"));
            EventLoop::delay(0.15, static function () use ($tui, $terminal, &$beforeClear): void {
                $beforeClear = $tui->agent()->getChatHistory()->getMessages();
                $terminal->simulateInput("/clear\r");
            });
            EventLoop::delay(0.19, static function () use ($tui, $terminal, &$afterClear): void {
                $afterClear = $tui->agent()->getChatHistory()->getMessages();
                $terminal->simulateInput("/resume\r");
            });
            EventLoop::delay(0.23, static fn () => $terminal->simulateInput("\r"));
            EventLoop::delay(0.29, static fn () => $terminal->simulateInput("\x03"));

            $tui->run();

            self::assertEquals($initialMessages, $startup);
            self::assertIsArray($beforeClear);
            self::assertCount($sessionStore === null ? 2 : 4, $beforeClear);
            self::assertSame('Generated continuation', $beforeClear[count($beforeClear) - 1]->getContent());
            self::assertSame([], $afterClear);
            self::assertEquals($beforeClear, $tui->agent()->getChatHistory()->getMessages());
            $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
            if ($sessionStore !== null) {
                self::assertStringContainsString('Initial subject', $display);
            }
            self::assertStringContainsString('Generated continuation', $display);
            if ($sessionStore !== null) {
                self::assertCount(1, $sessionStore->summaries());
                self::assertSame($selectedKey, $sessionStore->summaries()[0]->key);
            }
        }
    }

    public function testStartupDoesNotAutomaticallySelectAStoredSession(): void
    {
        $sessionStore = new SessionStore(new InMemoryStorage(), 'test-user');
        SessionHistory::of($sessionStore->create())->addMessage(new UserMessage('Stored subject'));
        $initial = new SeededHistory();
        $initial->addMessage(new UserMessage('Host selected subject'));
        $agent = (new Agent())->setThreadId('test-thread');
        $agent = ($initial)->bindTo($agent);
        $terminal = new VirtualTerminal();
        EventLoop::delay(0.12, static fn () => $terminal->simulateInput("\x03"));

        Tui::make($agent, $terminal, sessionStore: $sessionStore)->run();

        self::assertSame($initial->getThreadId(), $agent->getChatHistory()->getThreadId());
        self::assertCount(1, $sessionStore->summaries());
        self::assertNull($sessionStore->summaries()[0]->title);
        self::assertStringContainsString('Host selected subject', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testOmittedCommandsStayEmptyWithIndependentlySuppliedState(): void
    {
        foreach ([false, true] as $supplySessionStore) {
            foreach ([false, true] as $supplyInputs) {
                $terminal = new VirtualTerminal();
                $inputs = $supplyInputs ? new InputHistory(new InMemoryStorage()) : null;
                EventLoop::queue(static fn () => $terminal->simulateInput("/help\r"));
                EventLoop::delay(0.06, static fn () => $terminal->simulateInput("\x03"));

                (new Tui(
                    (new Agent())->setThreadId('test-thread'),
                    $terminal,
                    sessionStore: $supplySessionStore ? new SessionStore(new InMemoryStorage(), 'test-user') : null,
                    inputHistory: $inputs,
                ))->run();

                self::assertStringContainsString('Unknown Command: /help', \Symfony\Component\Tui\Ansi\AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                if ($inputs !== null) {
                    self::assertSame(['/help'], array_map(static fn (UserMessage $message): ?string => $message->getContent(), $inputs->entries()));
                }
            }
        }
    }

    public function testSuppliedAndDefaultModulesKeepTheirStateAcrossCommands(): void
    {
        foreach ([false, true] as $supplySessionStore) {
            foreach ([false, true] as $supplyInputs) {
                $sessionStore = $supplySessionStore ? new SessionStore(new InMemoryStorage(), 'test-user') : null;
                $inputs = $supplyInputs ? new InputHistory(new InMemoryStorage()) : null;
                $received = [];
                $command = $this->commandThat(
                    static function (CommandAdapterInterface $adapter) use (&$received): void {
                        $received[] = [$adapter->commands(), $adapter->sessionStore()];
                        if (count($received) === 1) {
                            SessionHistory::of($adapter->sessionStore()->create())->addMessage(new \NeuronAI\Chat\Messages\UserMessage('Kept by this module'));
                        } else {
                            self::assertCount(1, $adapter->sessionStore()->summaries());
                        }
                    },
                );
                $commands = new Commands();
                $terminal = new VirtualTerminal();
                $tui = Tui::make((new Agent())->setThreadId('test-thread'), $terminal, $commands, $sessionStore, inputHistory: $inputs);
                $commands->addCommand($command);
                EventLoop::queue(static fn () => $terminal->simulateInput("/inspect\r"));
                EventLoop::delay(0.04, static fn () => $terminal->simulateInput("\x1b[A\r"));
                EventLoop::delay(0.08, static fn () => $terminal->simulateInput("\x1b[A"));
                EventLoop::delay(0.12, static fn () => $terminal->simulateInput("\x03"));

                $tui->run();

                self::assertCount(2, $received);
                self::assertSame($commands, $received[0][0]);
                self::assertSame($received[0], $received[1]);
                if ($sessionStore !== null) {
                    self::assertSame($sessionStore, $received[0][1]);
                }
                if ($inputs !== null) {
                    self::assertSame(['/inspect'], array_map(static fn (UserMessage $message): ?string => $message->getContent(), $inputs->entries()));
                    self::assertTrue($inputs->isNavigating());
                }
            }
        }
    }

    public function testConfigurationStoreSurvivesSelectionAndIsScopedToTheTui(): void
    {
        $defaults = [];
        foreach ([false, true, false] as $supplyStore) {
            $storage = new InMemoryStorage();
            $store = $supplyStore ? new ConfigurationStore($storage, 'test-user') : null;
            $received = [];
            $command = $this->commandThat(
                static function (CommandAdapterInterface $adapter) use (&$received): void {
                    $configurationStore = $adapter->configurationStore();
                    $received[] = $configurationStore;
                    if (count($received) === 1) {
                        self::assertNull($configurationStore->read('model'));
                        $configurationStore->write('model', 'saved-model');
                        $adapter->requestSelection(new Selection('/inspect', 'Choose', [
                            new SelectionOption('selected-model', 'Selected model'),
                        ]));

                        return;
                    }

                    self::assertSame('saved-model', $configurationStore->read('model'));
                    $configurationStore->write('model', 'selected-model');
                    $adapter->stop();
                },
            );
            $terminal = new VirtualTerminal();
            EventLoop::queue(static fn () => $terminal->simulateInput("/inspect\r"));
            EventLoop::delay(0.04, static fn () => $terminal->simulateInput("\r"));
            $timeout = EventLoop::delay(0.15, static fn () => $terminal->simulateInput("\x03"));

            Tui::make((new Agent())->setThreadId('test-thread'), $terminal, (new Commands())->addCommand($command), configurationStore: $store)->run();
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

    public function testRuntimePreservesExternalHistoryWithoutRegisteringItInSessionStore(): void
    {
        foreach ([false, true] as $supplySessionStore) {
            $storage = new InMemoryStorage();
            $previous = new SeededHistory();
            $previous->addMessage(new UserMessage('External conversation'));
            $previous->addMessage(new AssistantMessage('External answer'));
            $agent = (new Agent())->setThreadId('test-thread');
            $agent = ($previous)->bindTo($agent);
            $terminal = new VirtualTerminal();
            $received = [];
            $command = $this->commandThat(
                static function (CommandAdapterInterface $adapter) use (&$received): void {
                    $received[] = $adapter->sessionStore();
                },
            );

            $tui = Tui::make($agent, $terminal, commands: (new Commands())->addCommand([$command, new ResumeCommand()]), sessionStore: $supplySessionStore ? new SessionStore($storage, 'test-user') : null);
            EventLoop::queue(
                static fn () => $terminal->simulateInput("/inspect\r"),
            );
            EventLoop::delay(
                0.04,
                static fn () => $terminal->simulateInput("/inspect\r"),
            );
            EventLoop::delay(
                0.1,
                static fn () => $terminal->simulateInput("\x03"),
            );

            EventLoop::delay(0.07, static fn () => $terminal->simulateInput("/resume\r"));

            $tui->run();

            self::assertCount(2, $received);
            self::assertInstanceOf(SessionStore::class, $received[0]);
            self::assertSame($received[0], $received[1]);
            self::assertSame($previous->getThreadId(), $tui->agent()->getChatHistory()->getThreadId());
            self::assertCount(2, $tui->agent()->getChatHistory()->getMessages());
            self::assertSame([], $received[0]->summaries());
            self::assertStringContainsString('External conversation', AnsiUtils::stripAnsiCodes($terminal->getOutput()));

            $entries = iterator_to_array($storage->entries('sessions'));
            self::assertSame([], $entries);
            self::assertStringContainsString('There is no earlier Session', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
        }
    }

    public function testSessionCommandsNeedNoParallelSessionDependency(): void
    {
        self::assertSame('/clear', (new ClearCommand())->name());
        self::assertSame('/wipe', (new ClearCommand('/wipe'))->name());
        self::assertSame('/resume', (new ResumeCommand())->name());
        self::assertSame('/return', (new ResumeCommand('/return'))->name());
    }

    public function testDefaultStoreUsesLocalOwner(): void
    {
        $terminal = new VirtualTerminal();
        $owner = null;
        $command = $this->commandThat(
            static function (CommandAdapterInterface $adapter) use (&$owner): void {
                $owner = $adapter->sessionStore()->create()->getUserId();
                $adapter->stop();
            },
        );
        EventLoop::queue(static fn () => $terminal->simulateInput("/inspect\r"));

        Tui::make((new Agent())->setThreadId('test-thread'), $terminal, (new Commands())->addCommand($command))->run();

        self::assertSame('local', $owner);
    }

    public function testSuppliedStoreKeepsItsOwner(): void
    {
        $sessionStore = new SessionStore(new InMemoryStorage(), 'store-owner');
        $terminal = new VirtualTerminal();
        $received = null;
        $owner = null;
        $command = $this->commandThat(
            static function (CommandAdapterInterface $adapter) use (&$received, &$owner): void {
                $received = $adapter->sessionStore();
                $owner = $received->create()->getUserId();
                $adapter->stop();
            },
        );
        EventLoop::queue(static fn () => $terminal->simulateInput("/inspect\r"));

        (new Tui(
            (new Agent())->setThreadId('test-thread'),
            $terminal,
            (new Commands())->addCommand($command),
            sessionStore: $sessionStore,
        ))->run();

        self::assertSame($sessionStore, $received);
        self::assertSame('store-owner', $owner);
    }

    public function testClearAndResumePreserveOnlyTheCurrentUsersPersistedConversation(): void
    {
        $directory = sys_get_temp_dir() . '/neuron-tui-scoped-' . bin2hex(random_bytes(6));

        try {
            $storage = new FileStorage($directory);
            $sessionStore = new SessionStore($storage, 'alice');
            $foreign = (new SessionStore($storage, 'bob'))->create();
            SessionHistory::of($foreign)->addMessage(new UserMessage('Private Bob subject'));
            $initial = $sessionStore->create();
            $agent = (new Agent())->setThreadId('test-thread');
            $agent = ($initial)->bindTo($agent);
            $provider = new FakeAIProvider(new AssistantMessage('Persisted Alice answer'));
            $agent->setAiProvider($provider);
            $terminal = new VirtualTerminal(rows: 30);
            $cleared = null;
            $picker = null;
            $tui = Tui::make($agent, $terminal, (new Commands())->addCommand([new ClearCommand(), new ResumeCommand()]), $sessionStore);
            EventLoop::queue(static fn () => $terminal->simulateInput("Alice subject\r"));
            EventLoop::delay(0.15, static fn () => $terminal->simulateInput("/clear\r"));
            EventLoop::delay(0.19, static function () use ($tui, $terminal, &$cleared): void {
                $cleared = $tui->agent()->getChatHistory();
                $terminal->simulateInput("/resume\r");
            });
            EventLoop::delay(0.23, static function () use ($terminal, &$picker): void {
                $picker = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                $terminal->simulateInput("\r");
            });
            EventLoop::delay(0.29, static fn () => $terminal->simulateInput("\x03"));

            $tui->run();

            self::assertInstanceOf(\NeuronAI\Chat\History\ChatHistory::class, $cleared);
            $clearedSession = $sessionStore->read($cleared->getThreadId());
            self::assertNotNull($clearedSession);
            self::assertSame('alice', $clearedSession->getUserId());
            self::assertNotSame($initial->getKey(), $cleared->getThreadId());
            self::assertSame([], $cleared->getMessages());
            self::assertIsString($picker);
            self::assertStringContainsString('Alice subject', $picker);
            self::assertStringNotContainsString('Private Bob subject', $picker);
            self::assertNotNull($sessionStore->read($tui->agent()->getChatHistory()->getThreadId()));
            self::assertSame($initial->getKey(), $tui->agent()->getChatHistory()->getThreadId());
            $reopened = (new SessionStore(new FileStorage($directory), 'alice'))->read($initial->getKey());
            self::assertNotNull($reopened);
            self::assertCount(2, $reopened->getMessages());
            self::assertSame('Persisted Alice answer', $reopened->getMessages()[1]->getContent());
            self::assertEquals($reopened->getMessages(), $tui->agent()->getChatHistory()->getMessages());
            self::assertNull($sessionStore->read($foreign->getKey()));
            self::assertNotNull((new SessionStore(new FileStorage($directory), 'bob'))->read($foreign->getKey()));
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
        SessionHistory::of($earlier)->addMessage(new UserMessage('Session removed during selection'));
        $initial = new SeededHistory();
        $initial->addMessage(new UserMessage('Current conversation'));
        $agent = (new Agent())->setThreadId('test-thread');
        $agent = ($initial)->bindTo($agent);
        $terminal = new VirtualTerminal();
        $tui = Tui::make($agent, $terminal, (new Commands())->addCommand(new ResumeCommand()), $sessionStore);
        EventLoop::queue(static fn () => $terminal->simulateInput("/resume\r"));
        EventLoop::delay(0.05, static function () use ($sessionStore, $earlier, $terminal): void {
            $sessionStore->delete($earlier->getKey());
            $terminal->simulateInput("\r");
        });
        EventLoop::delay(0.1, static fn () => $terminal->simulateInput("\x03"));

        $tui->run();

        self::assertSame($initial->getThreadId(), $tui->agent()->getChatHistory()->getThreadId());
        self::assertStringContainsString(
            'No Session is named by that key.',
            AnsiUtils::stripAnsiCodes($terminal->getOutput()),
        );
        self::assertSame([], $sessionStore->summaries());
    }

    /**
     * @param Closure(CommandAdapterInterface<mixed>): void $run
     */
    private function commandThat(Closure $run): CommandInterface
    {
        return new class($run) implements CommandInterface {
            /** @param Closure(CommandAdapterInterface<mixed>): void $run */
            public function __construct(private readonly Closure $run)
            {
            }

            public function name(): string
            {
                return '/inspect';
            }

            public function describe(): string
            {
                return 'Inspects the runtime composition.';
            }

            /** @param CommandAdapterInterface<mixed> $adapter */
            public function run(CommandAdapterInterface $adapter, string $value): void
            {
                ($this->run)($adapter);
            }
        };
    }
}
