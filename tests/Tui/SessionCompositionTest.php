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
use NeuronInteraction\Command\CommandAdapterInterface;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\ResumeCommand;
use NeuronInteraction\Command\Selection;
use NeuronInteraction\Command\SelectionOption;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Conversation;
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
    public function testManagedConversationsAndLaterTurnsCanBeClearedAndResumed(): void
    {
        foreach (['default', 'supplied', 'preselected'] as $composition) {
            $store = $composition === 'default' ? null : new SessionStore(new InMemoryStorage(), 'test-user');
            $session = $composition === 'preselected' && $store !== null ? $store->create() : null;
            if ($session !== null) {
                SessionHistory::of($session)->addMessage(new UserMessage('Initial subject'));
                SessionHistory::of($session)->addMessage(new AssistantMessage('Initial answer'));
            }
            $agent = (new Agent())->setAiProvider(new FakeAIProvider(new AssistantMessage('Generated continuation')));
            $terminal = new VirtualTerminal(rows: 40);
            $currentStore = null;
            $inspect = $this->commandThat(static function (CommandAdapterInterface $adapter) use (&$currentStore): void {
                $currentStore = $adapter->sessionStore();
            });
            $beforeClear = null;
            $originalKey = null;
            $afterClear = null;
            $conversation = new Conversation($agent, $store, session: $session);
            $tui = Tui::make($conversation, $terminal, (new Commands())->addCommand([new ClearCommand(), new ResumeCommand(), $inspect]));
            EventLoop::queue(static fn() => $terminal->simulateInput("Later question\r"));
            EventLoop::delay(0.12, static fn() => $terminal->simulateInput("/inspect\r"));
            EventLoop::delay(0.15, static function () use ($conversation, $terminal, &$beforeClear, &$originalKey): void {
                $beforeClear = $conversation->agent()->getChatHistory()->getMessages();
                $originalKey = $conversation->agent()->getThreadId();
                $terminal->simulateInput("/clear\r");
            });
            EventLoop::delay(0.19, static function () use ($conversation, $terminal, &$afterClear): void {
                $afterClear = $conversation->agent()->getChatHistory()->getMessages();
                $terminal->simulateInput("/resume\r");
            });
            EventLoop::delay(0.23, static fn() => $terminal->simulateInput("\r"));
            EventLoop::delay(0.29, static fn() => $terminal->simulateInput("\x03"));

            $tui->run();

            self::assertIsArray($beforeClear);
            self::assertCount($composition === 'preselected' ? 4 : 2, $beforeClear);
            self::assertSame([], $afterClear);
            self::assertEquals($beforeClear, $conversation->agent()->getChatHistory()->getMessages());
            self::assertSame($originalKey, $conversation->agent()->getThreadId());
            self::assertIsString($originalKey);
            self::assertInstanceOf(SessionStore::class, $currentStore);
            $stored = $currentStore->read($originalKey);
            self::assertNotNull($stored);
            self::assertEquals($beforeClear, $stored->getMessages());
        }
    }

    public function testStartupDoesNotAutomaticallySelectAStoredSession(): void
    {
        $store = new SessionStore(new InMemoryStorage(), 'test-user');
        $earlier = $store->create();
        SessionHistory::of($earlier)->addMessage(new UserMessage('Stored subject'));
        $terminal = new VirtualTerminal();
        $conversation = new Conversation(new Agent(), $store);
        $tui = Tui::make($conversation, $terminal);
        EventLoop::delay(0.05, static fn() => $terminal->simulateInput("\x03"));

        $tui->run();

        self::assertNotSame($earlier->getKey(), $conversation->agent()->getThreadId());
        self::assertSame([], $conversation->agent()->getChatHistory()->getMessages());
        self::assertNotNull($store->read($conversation->agent()->getChatHistory()->getThreadId()));
        self::assertCount(1, $store->summaries());
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

                (new Tui(
                    new Conversation((new Agent())->setThreadId('test-thread'), $supplySessionStore ? new SessionStore(new InMemoryStorage(), 'test-user') : null),
                    $terminal,
                    inputHistory: $inputs,
                ))->run();

                self::assertStringContainsString('Unknown Command: /help', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                if ($inputs !== null) {
                    self::assertSame(['/help'], array_map(static fn(UserMessage $message): ?string => $message->getContent(), $inputs->entries()));
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
                            SessionHistory::of($adapter->sessionStore()->create())->addMessage(new UserMessage('Kept by this module'));
                        } else {
                            self::assertCount(1, $adapter->sessionStore()->summaries());
                        }
                    },
                );
                $commands = new Commands();
                $terminal = new VirtualTerminal();
                $tui = Tui::make(new Conversation((new Agent())->setThreadId('test-thread'), $sessionStore), $terminal, $commands, inputHistory: $inputs);
                $commands->addCommand($command);
                EventLoop::queue(static fn() => $terminal->simulateInput("/inspect\r"));
                EventLoop::delay(0.04, static fn() => $terminal->simulateInput("\x1b[A\r"));
                EventLoop::delay(0.08, static fn() => $terminal->simulateInput("\x1b[A"));
                EventLoop::delay(0.12, static fn() => $terminal->simulateInput("\x03"));

                $tui->run();

                self::assertCount(2, $received);
                self::assertSame($commands, $received[0][0]);
                self::assertSame($received[0], $received[1]);
                if ($sessionStore !== null) {
                    self::assertSame($sessionStore, $received[0][1]);
                }
                if ($inputs !== null) {
                    self::assertSame(['/inspect'], array_map(static fn(UserMessage $message): ?string => $message->getContent(), $inputs->entries()));
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
            EventLoop::queue(static fn() => $terminal->simulateInput("/inspect\r"));
            EventLoop::delay(0.04, static fn() => $terminal->simulateInput("\r"));
            $timeout = EventLoop::delay(0.15, static fn() => $terminal->simulateInput("\x03"));

            Tui::make(new Conversation((new Agent())->setThreadId('test-thread'), new SessionStore(new InMemoryStorage(), 'local')), $terminal, (new Commands())->addCommand($command), configurationStore: $store)->run();
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
        $agent = $history->bindTo(new Agent());
        $store = new SessionStore(new InMemoryStorage(), 'test-user');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An Agent with existing messages requires an explicit Session.');

        Tui::make(new Conversation($agent, $store), new VirtualTerminal())->run();
    }

    public function testExplicitSessionDeterminesTheConversationWithoutImportingAgentMessages(): void
    {
        $store = new SessionStore(new InMemoryStorage(), 'test-user');
        $session = $store->create();
        SessionHistory::of($session)->addMessage(new UserMessage('Selected conversation'));
        $history = new SeededHistory();
        $history->addMessage(new UserMessage('External conversation'));
        $agent = $history->bindTo(new Agent());
        $terminal = new VirtualTerminal();
        $conversation = new Conversation($agent, $store, session: $session);
        $tui = Tui::make($conversation, $terminal);
        EventLoop::delay(0.05, static fn() => $terminal->simulateInput("\x03"));

        $tui->run();

        self::assertSame($session->getKey(), $conversation->agent()->getThreadId());
        self::assertEquals($session->getMessages(), $conversation->agent()->getChatHistory()->getMessages());
        self::assertSame($history->getThreadId(), $agent->getThreadId());
        self::assertCount(1, $session->getMessages());
        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString('Selected conversation', $display);
        self::assertStringNotContainsString('External conversation', $display);
    }

    public function testStartupRejectsASessionOwnedByAnotherUser(): void
    {
        $storage = new InMemoryStorage();
        $foreign = (new SessionStore($storage, 'bob'))->create();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The selected Session does not belong to this SessionStore.');

        Tui::make(new Conversation(new Agent(), new SessionStore($storage, 'alice'), session: $foreign), new VirtualTerminal())->run();
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
        EventLoop::queue(static fn() => $terminal->simulateInput("/inspect\r"));

        Tui::make(new Conversation((new Agent())->setThreadId('test-thread'), new SessionStore(new InMemoryStorage(), 'local')), $terminal, (new Commands())->addCommand($command))->run();

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
        EventLoop::queue(static fn() => $terminal->simulateInput("/inspect\r"));

        (new Tui(
            new Conversation((new Agent())->setThreadId('test-thread'), $sessionStore),
            $terminal,
            (new Commands())->addCommand($command),
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
            $conversation = new Conversation($agent, $sessionStore, session: $initial);
            $tui = Tui::make($conversation, $terminal, (new Commands())->addCommand([new ClearCommand(), new ResumeCommand()]));
            EventLoop::queue(static fn() => $terminal->simulateInput("Alice subject\r"));
            EventLoop::delay(0.15, static fn() => $terminal->simulateInput("/clear\r"));
            EventLoop::delay(0.19, static function () use ($conversation, $terminal, &$cleared): void {
                $cleared = $conversation->agent()->getChatHistory();
                $terminal->simulateInput("/resume\r");
            });
            EventLoop::delay(0.23, static function () use ($terminal, &$picker): void {
                $picker = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                $terminal->simulateInput("\r");
            });
            EventLoop::delay(0.29, static fn() => $terminal->simulateInput("\x03"));

            $tui->run();

            self::assertInstanceOf(ChatHistory::class, $cleared);
            $clearedSession = $sessionStore->read($cleared->getThreadId());
            self::assertNotNull($clearedSession);
            self::assertSame('alice', $clearedSession->getUserId());
            self::assertNotSame($initial->getKey(), $cleared->getThreadId());
            self::assertSame([], $cleared->getMessages());
            self::assertIsString($picker);
            self::assertStringContainsString('Alice subject', $picker);
            self::assertStringNotContainsString('Private Bob subject', $picker);
            self::assertNotNull($sessionStore->read($conversation->agent()->getChatHistory()->getThreadId()));
            self::assertSame($initial->getKey(), $conversation->agent()->getChatHistory()->getThreadId());
            $reopened = (new SessionStore(new FileStorage($directory), 'alice'))->read($initial->getKey());
            self::assertNotNull($reopened);
            self::assertCount(2, $reopened->getMessages());
            self::assertSame('Persisted Alice answer', $reopened->getMessages()[1]->getContent());
            self::assertEquals($reopened->getMessages(), $conversation->agent()->getChatHistory()->getMessages());
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
        $earlier->setTitle('Removed target');
        SessionHistory::of($earlier)->addMessage(new UserMessage('Session removed during selection'));
        $initial = $sessionStore->create();
        SessionHistory::of($initial)->addMessage(new UserMessage('Current conversation'));
        $agent = (new Agent())->setThreadId('test-thread');
        $agent = ($initial)->bindTo($agent);
        $terminal = new VirtualTerminal();
        $conversation = new Conversation($agent, $sessionStore, session: $initial);
        $tui = Tui::make($conversation, $terminal, (new Commands())->addCommand(new ResumeCommand()));
        EventLoop::queue(static fn() => $terminal->simulateInput("/resume\r"));
        EventLoop::delay(0.03, static fn() => $terminal->simulateInput("\x1b[B"));
        EventLoop::delay(0.05, static function () use ($sessionStore, $earlier, $terminal): void {
            $sessionStore->delete($earlier->getKey());
            $terminal->simulateInput("\r");
        });
        EventLoop::delay(0.1, static fn() => $terminal->simulateInput("\x03"));

        $tui->run();

        self::assertSame($initial->getKey(), $conversation->agent()->getChatHistory()->getThreadId());
        self::assertStringContainsString(
            'No Session is named by that key.',
            AnsiUtils::stripAnsiCodes($terminal->getOutput()),
        );
        self::assertCount(1, $sessionStore->summaries());
    }

    /**
     * @param Closure(CommandAdapterInterface<mixed>): void $run
     */
    private function commandThat(Closure $run): CommandInterface
    {
        return new class ($run) implements CommandInterface {
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
            public function run(CommandAdapterInterface $adapter, string $value): void
            {
                ($this->run)($adapter);
            }
        };
    }
}
