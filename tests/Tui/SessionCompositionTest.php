<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Tui;

use Closure;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\InputHistory\InputHistory;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronInteraction\Command\CommandArguments;
use NeuronInteraction\Command\ClearCommand;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\ResumeCommand;
use NeuronInteraction\Command\SessionCommandKit;
use NeuronInteraction\Command\CommandAdapterInterface;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteraction\Storage\FileStorage;
use NeuronInteraction\Session\Session;
use NeuronTui\Tui;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class SessionCompositionTest extends TestCase
{
    public function testManagedConversationsAndLaterTurnsCanBeClearedAndResumed(): void
    {
        foreach (['default', 'preselected'] as $composition) {
            $sessions = $composition === 'default' ? null : new SessionStore(new InMemoryStorage(), 'test-user');
            $initial = $sessions !== null
                ? $sessions->create()
                : new InMemoryChatHistory();
            $initial->addMessage(new UserMessage('Initial subject'));
            $initial->addMessage(new AssistantMessage('Initial answer'));
            $selectedKey = null;
            if ($sessions !== null) {
                $selectedKey = $sessions->summaries()[0]->key;
                $initial = $sessions->read($selectedKey);
                self::assertNotNull($initial);
            }
            $initialMessages = $initial->getMessages();
            $agent = new Agent();
            $agent->setChatHistory($initial);
            $agent->setAiProvider(new FakeAIProvider(new AssistantMessage('Generated continuation')));
            $terminal = new VirtualTerminal(rows: 40);
            $startup = null;
            $beforeClear = null;
            $afterClear = null;
            EventLoop::queue(static function () use ($agent, &$startup): void {
                $startup = $agent->getChatHistory()->getMessages();
            });
            if ($sessions === null) {
                // The default collection starts managing History only after /clear.
                EventLoop::delay(0.01, static fn () => $terminal->simulateInput("/clear\r"));
            }
            EventLoop::delay(0.03, static fn () => $terminal->simulateInput("Later question\r"));
            EventLoop::delay(0.15, static function () use ($agent, $terminal, &$beforeClear): void {
                $beforeClear = $agent->getChatHistory()->getMessages();
                $terminal->simulateInput("/clear\r");
            });
            EventLoop::delay(0.19, static function () use ($agent, $terminal, &$afterClear): void {
                $afterClear = $agent->getChatHistory()->getMessages();
                $terminal->simulateInput("/resume\r");
            });
            EventLoop::delay(0.23, static fn () => $terminal->simulateInput("\r"));
            EventLoop::delay(0.29, static fn () => $terminal->simulateInput("\x03"));

            Tui::make($agent, $terminal, new Commands(new SessionCommandKit()), $sessions)->run();

            self::assertEquals($initialMessages, $startup);
            self::assertIsArray($beforeClear);
            self::assertCount($sessions === null ? 2 : 4, $beforeClear);
            self::assertSame('Generated continuation', $beforeClear[count($beforeClear) - 1]->getContent());
            self::assertSame([], $afterClear);
            self::assertEquals($beforeClear, $agent->getChatHistory()->getMessages());
            $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
            if ($sessions !== null) {
                self::assertStringContainsString('Initial subject', $display);
            }
            self::assertStringContainsString('Generated continuation', $display);
            if ($sessions !== null) {
                self::assertCount(1, $sessions->summaries());
                self::assertSame($selectedKey, $sessions->summaries()[0]->key);
            }
        }
    }

    public function testStartupDoesNotAutomaticallySelectAStoredSession(): void
    {
        $sessions = new SessionStore(new InMemoryStorage(), 'test-user');
        $sessions->create()->addMessage(new UserMessage('Stored subject'));
        $initial = new InMemoryChatHistory();
        $initial->addMessage(new UserMessage('Host selected subject'));
        $agent = new Agent();
        $agent->setChatHistory($initial);
        $terminal = new VirtualTerminal();
        EventLoop::delay(0.12, static fn () => $terminal->simulateInput("\x03"));

        Tui::make($agent, $terminal, sessions: $sessions)->run();

        self::assertSame($initial, $agent->getChatHistory());
        self::assertCount(1, $sessions->summaries());
        self::assertSame('Stored subject', $sessions->summaries()[0]->title);
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
                    new Agent(),
                    $terminal,
                    sessions: $supplySessionStore ? new SessionStore(new InMemoryStorage(), 'test-user') : null,
                    inputHistory: $inputs,
                ))->run();

                self::assertStringContainsString('Unknown Command: /help', \Symfony\Component\Tui\Ansi\AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                if ($inputs !== null) {
                    self::assertSame(['/help'], $inputs->entries());
                }
            }
        }
    }

    public function testSuppliedAndDefaultModulesKeepTheirStateAcrossCommands(): void
    {
        foreach ([false, true] as $supplySessionStore) {
            foreach ([false, true] as $supplyInputs) {
                $sessions = $supplySessionStore ? new SessionStore(new InMemoryStorage(), 'test-user') : null;
                $inputs = $supplyInputs ? new InputHistory(new InMemoryStorage()) : null;
                $received = [];
                $command = $this->commandThat(
                    static function (CommandAdapterInterface $adapter) use (&$received): void {
                        $received[] = [$adapter->commands(), $adapter->sessions()];
                        if (count($received) === 1) {
                            $adapter->sessions()->create()->addMessage(new \NeuronAI\Chat\Messages\UserMessage('Kept by this module'));
                        } else {
                            self::assertCount(1, $adapter->sessions()->summaries());
                        }
                    },
                );
                $commands = new Commands();
                $terminal = new VirtualTerminal();
                $tui = Tui::make(new Agent(), $terminal, $commands, $sessions, $inputs);
                $commands->addCommand($command);
                EventLoop::queue(static fn () => $terminal->simulateInput("/inspect\r"));
                EventLoop::delay(0.04, static fn () => $terminal->simulateInput("\x1b[A\r"));
                EventLoop::delay(0.08, static fn () => $terminal->simulateInput("\x1b[A"));
                EventLoop::delay(0.12, static fn () => $terminal->simulateInput("\x03"));

                $tui->run();

                self::assertCount(2, $received);
                self::assertSame($commands, $received[0][0]);
                self::assertSame($received[0], $received[1]);
                if ($sessions !== null) {
                    self::assertSame($sessions, $received[0][1]);
                }
                if ($inputs !== null) {
                    self::assertSame(['/inspect'], $inputs->entries());
                    self::assertTrue($inputs->isNavigating());
                }
            }
        }
    }

    public function testRuntimePreservesExternalHistoryWithoutRegisteringItInSessionStore(): void
    {
        foreach ([false, true] as $supplySessionStore) {
            $storage = new InMemoryStorage();
            $previous = new InMemoryChatHistory();
            $previous->addMessage(new UserMessage('External conversation'));
            $previous->addMessage(new AssistantMessage('External answer'));
            $agent = new Agent();
            $agent->setChatHistory($previous);
            $terminal = new VirtualTerminal();
            $received = [];
            $command = $this->commandThat(
                static function (CommandAdapterInterface $adapter) use (&$received): void {
                    $received[] = $adapter->sessions();
                },
            );

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

            Tui::make($agent, $terminal, commands: new Commands([$command, new ResumeCommand()]), sessions: $supplySessionStore ? new SessionStore($storage, 'test-user') : null)->run();

            self::assertCount(2, $received);
            self::assertInstanceOf(SessionStore::class, $received[0]);
            self::assertSame($received[0], $received[1]);
            self::assertSame($previous, $agent->getChatHistory());
            self::assertCount(2, $agent->getChatHistory()->getMessages());
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

        self::assertSame(
            [ClearCommand::class, ResumeCommand::class],
            array_map(
                static fn (
                    CommandInterface $command,
                ): string => $command::class,
                (new SessionCommandKit())->commands(),
            ),
        );
    }

    public function testLocalIdentityPrecedenceAndStableFallbackAtComposition(): void
    {
        $previous = [];
        foreach (['USER', 'USERNAME', 'LOGNAME'] as $name) {
            $previous[$name] = getenv($name);
            putenv($name);
        }

        try {
            foreach ([
                ['configured', 'system-user', 'configured'],
                [null, 'system-user', 'system-user'],
                ['', 'system-user', 'system-user'],
                [null, null, 'local'],
                [null, null, 'local'],
            ] as [$configured, $systemUser, $expected]) {
                putenv($systemUser === null ? 'USER' : 'USER=' . $systemUser);
                $agent = new Agent();
                $terminal = new VirtualTerminal();
                $owner = null;
                $command = $this->commandThat(
                    static function (CommandAdapterInterface $adapter) use (&$owner): void {
                        $owner = $adapter->sessions()->create()->getUserId();
                        $adapter->stop();
                    },
                );
                EventLoop::queue(static fn () => $terminal->simulateInput("/inspect\r"));

                Tui::make($agent, $terminal, new Commands($command), userId: $configured)->run();

                self::assertSame($expected, $owner);
            }

            putenv('USERNAME=windows-user');
            self::assertSame('windows-user', \NeuronTui\LocalUserId::resolve());
            putenv('USERNAME');
            putenv('LOGNAME=login-user');
            self::assertSame('login-user', \NeuronTui\LocalUserId::resolve());
        } finally {
            foreach ($previous as $name => $value) {
                putenv($value === false ? $name : $name . '=' . $value);
            }
        }
    }

    public function testSuppliedStoreKeepsItsOwnerDespiteConfiguredIdentity(): void
    {
        $sessions = new SessionStore(new InMemoryStorage(), 'store-owner');
        $terminal = new VirtualTerminal();
        $received = null;
        $owner = null;
        $command = $this->commandThat(
            static function (CommandAdapterInterface $adapter) use (&$received, &$owner): void {
                $received = $adapter->sessions();
                $owner = $received->create()->getUserId();
                $adapter->stop();
            },
        );
        EventLoop::queue(static fn () => $terminal->simulateInput("/inspect\r"));

        (new Tui(
            new Agent(),
            $terminal,
            new Commands($command),
            sessions: $sessions,
            userId: 'another-user',
        ))->run();

        self::assertSame($sessions, $received);
        self::assertSame('store-owner', $owner);
    }

    public function testClearAndResumePreserveOnlyTheCurrentUsersPersistedConversation(): void
    {
        $directory = sys_get_temp_dir() . '/neuron-tui-scoped-' . bin2hex(random_bytes(6));

        try {
            $storage = new FileStorage($directory);
            $sessions = new SessionStore($storage, 'alice');
            $foreign = (new SessionStore($storage, 'bob'))->create();
            $foreign->addMessage(new UserMessage('Private Bob subject'));
            $initial = $sessions->create();
            $agent = new Agent();
            $agent->setChatHistory($initial);
            $provider = new FakeAIProvider(new AssistantMessage('Persisted Alice answer'));
            $agent->setAiProvider($provider);
            $terminal = new VirtualTerminal(rows: 30);
            $cleared = null;
            $picker = null;
            EventLoop::queue(static fn () => $terminal->simulateInput("Alice subject\r"));
            EventLoop::delay(0.15, static fn () => $terminal->simulateInput("/clear\r"));
            EventLoop::delay(0.19, static function () use ($agent, $terminal, &$cleared): void {
                $cleared = $agent->getChatHistory();
                $terminal->simulateInput("/resume\r");
            });
            EventLoop::delay(0.23, static function () use ($terminal, &$picker): void {
                $picker = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                $terminal->simulateInput("\r");
            });
            EventLoop::delay(0.29, static fn () => $terminal->simulateInput("\x03"));

            Tui::make($agent, $terminal, new Commands(new SessionCommandKit()), $sessions)->run();

            self::assertInstanceOf(Session::class, $cleared);
            self::assertSame('alice', $cleared->getUserId());
            self::assertNotSame($initial->getKey(), $cleared->getKey());
            self::assertSame([], $cleared->getMessages());
            self::assertIsString($picker);
            self::assertStringContainsString('Alice subject', $picker);
            self::assertStringNotContainsString('Private Bob subject', $picker);
            self::assertInstanceOf(Session::class, $agent->getChatHistory());
            self::assertSame($initial->getKey(), $agent->getChatHistory()->getKey());
            $reopened = (new SessionStore(new FileStorage($directory), 'alice'))->read($initial->getKey());
            self::assertNotNull($reopened);
            self::assertCount(2, $reopened->getMessages());
            self::assertSame('Persisted Alice answer', $reopened->getMessages()[1]->getContent());
            self::assertEquals($reopened->getMessages(), $agent->getChatHistory()->getMessages());
            self::assertNull($sessions->read($foreign->getKey()));
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
        $sessions = new SessionStore(new InMemoryStorage(), 'alice');
        $earlier = $sessions->create();
        $earlier->addMessage(new UserMessage('Session removed during selection'));
        $initial = new InMemoryChatHistory();
        $initial->addMessage(new UserMessage('Current conversation'));
        $agent = new Agent();
        $agent->setChatHistory($initial);
        $terminal = new VirtualTerminal();
        EventLoop::queue(static fn () => $terminal->simulateInput("/resume\r"));
        EventLoop::delay(0.05, static function () use ($sessions, $earlier, $terminal): void {
            $sessions->delete($earlier->getKey());
            $terminal->simulateInput("\r");
        });
        EventLoop::delay(0.1, static fn () => $terminal->simulateInput("\x03"));

        Tui::make($agent, $terminal, new Commands(new ResumeCommand()), $sessions)->run();

        self::assertSame($initial, $agent->getChatHistory());
        self::assertStringContainsString(
            'No Session is named by that key.',
            AnsiUtils::stripAnsiCodes($terminal->getOutput()),
        );
        self::assertSame([], $sessions->summaries());
    }

    /**
     * @param Closure(CommandAdapterInterface<mixed>): void $run
     */
    private function commandThat(Closure $run): CommandInterface
    {
        return new class($run) implements CommandInterface {
            /** @param Closure(CommandAdapterInterface<mixed>): void $run */
            public function __construct(private readonly Closure $run) {}

            public function name(): string
            {
                return '/inspect';
            }

            public function describe(): string
            {
                return 'Inspects the runtime composition.';
            }

            /** @param CommandAdapterInterface<mixed> $adapter */
            public function run(CommandAdapterInterface $adapter, CommandArguments $arguments): void
            {
                ($this->run)($adapter);
            }
        };
    }
}
