<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Tui;

use Closure;
use NeuronInteraction\Agent\AgentFactoryRegistry;
use NeuronInteraction\Configuration\Configuration;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Command\SelectionOption;
use NeuronInteraction\Command\SelectionRequest;
use NeuronTui\Tests\Support\ConfiguredAgent;
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
use NeuronInteraction\Command\CommandControlsAdapterInterface;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteraction\Storage\FileStorage;
use NeuronInteraction\Session\Session;
use NeuronTui\Tui;
use NeuronTui\Tests\Support\ObservedCommand;
use NeuronTui\Tests\Support\SelfConfiguredAgent;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class SessionCompositionTest extends TestCase
{
    public function testManagedConversationsAndLaterTurnsCanBeClearedAndResumed(): void
    {
        foreach (['default', 'preselected'] as $composition) {
            $sessionStore = $composition === 'default' ? null : new SessionStore(new InMemoryStorage(), 'test-user');
            $initial = $sessionStore !== null
                ? $sessionStore->create()
                : new InMemoryChatHistory();
            $initial->addMessage(new UserMessage('Initial subject'));
            $initial->addMessage(new AssistantMessage('Initial answer'));
            $selectedKey = null;
            if ($sessionStore !== null) {
                $selectedKey = $sessionStore->summaries()[0]->key;
                $initial = $sessionStore->read($selectedKey);
                self::assertNotNull($initial);
            }
            $initialMessages = $initial->getMessages();
            $agent = new SelfConfiguredAgent();
            $agent->setChatHistory($initial);
            $agent->setAiProvider(new FakeAIProvider(new AssistantMessage('An answer.')));
            $terminal = new VirtualTerminal(rows: 40);
            $startup = null;
            $beforeClear = null;
            $afterClear = null;
            EventLoop::queue(static function () use (&$agent, &$startup): void {
                $startup = $agent->getChatHistory()->getMessages();
            });
            if ($sessionStore === null) {
                // The default collection starts managing History only after /clear.
                EventLoop::delay(0.01, static fn () => $terminal->simulateInput("/clear\r"));
            }
            EventLoop::delay(0.03, static fn () => $terminal->simulateInput("Later question\r"));
            EventLoop::delay(0.15, static function () use (&$agent, $terminal, &$beforeClear): void {
                $beforeClear = $agent->getChatHistory()->getMessages();
                $terminal->simulateInput("/clear\r");
            });
            EventLoop::delay(0.19, static function () use (&$agent, $terminal, &$afterClear): void {
                $afterClear = $agent->getChatHistory()->getMessages();
                $terminal->simulateInput("/resume\r");
            });
            EventLoop::delay(0.23, static fn () => $terminal->simulateInput("\r"));
            EventLoop::delay(0.29, static fn () => $terminal->simulateInput("\x03"));

            Tui::make($agent, $terminal, $this->sessionCommands($agent), $sessionStore, agentFactoryRegistry: SelfConfiguredAgent::registry(), configurationStore: SelfConfiguredAgent::configurationStore())->run();

            self::assertEquals($initialMessages, $startup);
            self::assertIsArray($beforeClear);
            self::assertCount($sessionStore === null ? 2 : 4, $beforeClear);
            self::assertSame('An answer.', $beforeClear[count($beforeClear) - 1]->getContent());
            self::assertSame([], $afterClear);
            self::assertEquals($beforeClear, $agent->getChatHistory()->getMessages());
            $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
            if ($sessionStore !== null) {
                self::assertStringContainsString('Initial subject', $display);
            }
            self::assertStringContainsString('An answer.', $display);
            if ($sessionStore !== null) {
                self::assertCount(1, $sessionStore->summaries());
                self::assertSame($selectedKey, $sessionStore->summaries()[0]->key);
            }
        }
    }

    public function testStartupDoesNotAutomaticallySelectAStoredSession(): void
    {
        $sessionStore = new SessionStore(new InMemoryStorage(), 'test-user');
        $sessionStore->create()->addMessage(new UserMessage('Stored subject'));
        $initial = new InMemoryChatHistory();
        $initial->addMessage(new UserMessage('Host selected subject'));
        $agent = new Agent();
        $agent->setChatHistory($initial);
        $terminal = new VirtualTerminal();
        EventLoop::delay(0.12, static fn () => $terminal->simulateInput("\x03"));

        Tui::make($agent, $terminal, sessionStore: $sessionStore)->run();

        self::assertSame($initial, $agent->getChatHistory());
        self::assertCount(1, $sessionStore->summaries());
        self::assertSame('Stored subject', $sessionStore->summaries()[0]->title);
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
                    sessionStore: $supplySessionStore ? new SessionStore(new InMemoryStorage(), 'test-user') : null,
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
                $sessionStore = $supplySessionStore ? new SessionStore(new InMemoryStorage(), 'test-user') : null;
                $inputs = $supplyInputs ? new InputHistory(new InMemoryStorage()) : null;
                $received = [];
                $command = $this->commandThat(
                    static function (CommandControlsAdapterInterface $adapter) use (&$received): void {
                        $received[] = [$adapter->commands(), $adapter->sessionStore(), $adapter->agentFactoryRegistry(), $adapter->configurationStore()];
                        if (count($received) === 1) {
                            $adapter->sessionStore()->create()->addMessage(new \NeuronAI\Chat\Messages\UserMessage('Kept by this module'));
                        } else {
                            self::assertCount(1, $adapter->sessionStore()->summaries());
                        }
                    },
                );
                $commands = new Commands();
                $terminal = new VirtualTerminal();
                $tui = Tui::make(new Agent(), $terminal, $commands, $sessionStore, $inputs);
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
                static function (CommandControlsAdapterInterface $adapter) use (&$received): void {
                    $received[] = $adapter->sessionStore();
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

            Tui::make($agent, $terminal, commands: new Commands([$command, new ResumeCommand()]), sessionStore: $supplySessionStore ? new SessionStore($storage, 'test-user') : null)->run();

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

    public function testDefaultStoreUsesLocalOwner(): void
    {
        $terminal = new VirtualTerminal();
        $owner = null;
        $command = $this->commandThat(
            static function (CommandControlsAdapterInterface $adapter) use (&$owner): void {
                $owner = $adapter->sessionStore()->create()->getUserId();
                $adapter->stop();
            },
        );
        EventLoop::queue(static fn () => $terminal->simulateInput("/inspect\r"));

        Tui::make(new Agent(), $terminal, new Commands($command))->run();

        self::assertSame('local', $owner);
    }

    public function testSuppliedStoreKeepsItsOwner(): void
    {
        $sessionStore = new SessionStore(new InMemoryStorage(), 'store-owner');
        $terminal = new VirtualTerminal();
        $received = null;
        $owner = null;
        $command = $this->commandThat(
            static function (CommandControlsAdapterInterface $adapter) use (&$received, &$owner): void {
                $received = $adapter->sessionStore();
                $owner = $received->create()->getUserId();
                $adapter->stop();
            },
        );
        EventLoop::queue(static fn () => $terminal->simulateInput("/inspect\r"));

        (new Tui(
            new Agent(),
            $terminal,
            new Commands($command),
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
            $foreign->addMessage(new UserMessage('Private Bob subject'));
            $initial = $sessionStore->create();
            $agent = new Agent();
            $agent->setChatHistory($initial);
            $provider = new FakeAIProvider(new AssistantMessage('Persisted Alice answer'));
            $agent->setAiProvider($provider);
            $terminal = new VirtualTerminal(rows: 30);
            $cleared = null;
            $picker = null;
            EventLoop::queue(static fn () => $terminal->simulateInput("Alice subject\r"));
            EventLoop::delay(0.15, static fn () => $terminal->simulateInput("/clear\r"));
            EventLoop::delay(0.19, static function () use (&$agent, $terminal, &$cleared): void {
                $cleared = $agent->getChatHistory();
                $terminal->simulateInput("/resume\r");
            });
            EventLoop::delay(0.23, static function () use ($terminal, &$picker): void {
                $picker = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                $terminal->simulateInput("\r");
            });
            EventLoop::delay(0.29, static fn () => $terminal->simulateInput("\x03"));

            Tui::make($agent, $terminal, $this->sessionCommands($agent), $sessionStore, agentFactoryRegistry: SelfConfiguredAgent::registry(), configurationStore: SelfConfiguredAgent::configurationStore())->run();

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
        $earlier->addMessage(new UserMessage('Session removed during selection'));
        $initial = new InMemoryChatHistory();
        $initial->addMessage(new UserMessage('Current conversation'));
        $agent = new Agent();
        $agent->setChatHistory($initial);
        $terminal = new VirtualTerminal();
        EventLoop::queue(static fn () => $terminal->simulateInput("/resume\r"));
        EventLoop::delay(0.05, static function () use ($sessionStore, $earlier, $terminal): void {
            $sessionStore->delete($earlier->getKey());
            $terminal->simulateInput("\r");
        });
        EventLoop::delay(0.1, static fn () => $terminal->simulateInput("\x03"));

        Tui::make($agent, $terminal, new Commands(new ResumeCommand()), $sessionStore)->run();

        self::assertSame($initial, $agent->getChatHistory());
        self::assertStringContainsString(
            'No Session is named by that key.',
            AnsiUtils::stripAnsiCodes($terminal->getOutput()),
        );
        self::assertSame([], $sessionStore->summaries());
    }

    public function testClearAndDeferredResumeUseRequiredDependenciesAndTheLatestSavedCapabilities(): void
    {
        $storage = new InMemoryStorage();
        $sessions = new SessionStore($storage, 'alice');
        $configurations = new ConfigurationStore($storage, 'alice');
        $configuration = $configurations->create('global', ['agent' => 'configured', 'model' => 'selected', 'capability' => 'enabled']);
        $registry = new AgentFactoryRegistry();
        $registry->register('configured', static function (Configuration $configuration): Agent {
            $model = $configuration->get('model');
            $capability = $configuration->get('capability');
            self::assertIsString($model);
            self::assertIsString($capability);

            return (new ConfiguredAgent(
                static fn (string $option): FakeAIProvider => new FakeAIProvider(new AssistantMessage($model . ' / ' . $option)),
            ))->setCapability($capability);
        });
        $agent = $registry->create($configuration);
        $original = $agent;
        $initial = $sessions->create();
        $agent->setChatHistory($initial);
        $terminal = new VirtualTerminal(rows: 40);
        EventLoop::queue(static fn () => $terminal->simulateInput("Initial question\r"));
        EventLoop::delay(0.15, static fn () => $terminal->simulateInput("/clear\r"));
        EventLoop::delay(0.2, static fn () => $terminal->simulateInput("After clear\r"));
        $clearedAgent = null;
        EventLoop::delay(0.4, static function () use (&$agent, &$clearedAgent, $terminal): void {
            $clearedAgent = $agent;
            $terminal->simulateInput("/resume\r");
        });
        EventLoop::delay(0.45, static function () use ($configuration, $configurations, $terminal): void {
            $configuration->set('model', 'latest');
            $configuration->set('capability', 'updated');
            $configurations->save($configuration);
            // Select the original Session after the newer cleared one.
            $terminal->simulateInput("\x1b[B");
        });
        EventLoop::delay(0.48, static fn () => $terminal->simulateInput("\r"));
        EventLoop::delay(0.55, static fn () => $terminal->simulateInput("After resume\r"));
        EventLoop::delay(0.7, static fn () => $terminal->simulateInput("\x03"));

        Tui::make($agent, $terminal, $this->sessionCommands($agent), $sessions,
            agentFactoryRegistry: $registry, configurationStore: $configurations,
        )->run();

        self::assertNotSame($original, $agent);
        $current = $agent->getChatHistory();
        self::assertInstanceOf(Session::class, $current);
        self::assertNotNull($clearedAgent);
        self::assertNotSame($original, $clearedAgent);
        self::assertNotSame($clearedAgent, $agent);
        self::assertNotSame($initial->getKey(), $clearedAgent->getThreadId());
        self::assertSame('selected / enabled', $clearedAgent->getChatHistory()->getMessages()[1]->getContent());
        self::assertSame($initial->getKey(), $current->getKey());
        self::assertSame($current->getKey(), $agent->getThreadId());
        self::assertCount(4, $current->getMessages());
        self::assertSame('latest / updated', $current->getMessages()[3]->getContent());
        self::assertSame('selected / enabled', $initial->getMessages()[1]->getContent());
        self::assertCount(2, $sessions->summaries());
        self::assertSame($configuration->all(), $configurations->read('global')?->all());
        self::assertStringContainsString('selected / enabled', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testClearAndResumePreparationFailuresKeepTheCurrentAgentUsable(): void
    {
        foreach (['/clear', '/resume'] as $identifier) {
            foreach (['missing', 'unknown', 'throwing'] as $failure) {
                $registry = new AgentFactoryRegistry();
                $configurations = new ConfigurationStore(new InMemoryStorage(), 'alice');
                if ($failure !== 'missing') {
                    $configurations->create('global', ['agent' => $failure]);
                }
                $registry->register('throwing', static function (): Agent {
                    throw new \RuntimeException('Factory dependency unavailable.');
                });
                $agent = new Agent();
                $original = $agent;
                $history = $agent->getChatHistory();
                $agent->setAiProvider(new FakeAIProvider(new AssistantMessage('Still answering.')));
                $terminal = new VirtualTerminal(rows: 40);
                $sessions = new SessionStore(new InMemoryStorage(), 'alice');
                $saved = $sessions->create();
                EventLoop::queue(static fn () => $terminal->simulateInput($identifier . ($identifier === '/resume' ? ' ' . $saved->getKey() : '') . "\r"));
                EventLoop::delay(0.05, static fn () => $terminal->simulateInput("Continue\r"));
                EventLoop::delay(0.2, static fn () => $terminal->simulateInput("\x03"));

                Tui::make($agent, $terminal, $this->sessionCommands($agent), $sessions,
                    agentFactoryRegistry: $registry, configurationStore: $configurations,
                )->run();

                self::assertSame($original, $agent);
                self::assertSame($history, $agent->getChatHistory());
                self::assertSame('Still answering.', $history->getMessages()[1]->getContent());
                $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                self::assertStringContainsString('Still answering.', $display);
                self::assertStringContainsString(match ($failure) {
                    'missing' => 'General configuration "global" is missing.',
                    'unknown' => 'unknown',
                    'throwing' => 'Factory dependency unavailable.',
                }, $display);
            }
        }
    }

    public function testDeferredSelectionRetainsTheSuppliedModulesAndReadsFreshConfiguration(): void
    {
        $registry = new AgentFactoryRegistry();
        $configurations = new ConfigurationStore(new InMemoryStorage(), 'alice');
        $configuration = $configurations->create('global', ['choice' => 'before']);
        $received = [];
        $command = $this->commandThat(static function (CommandControlsAdapterInterface $adapter) use (&$received): void {
            $received[] = [$adapter->agentFactoryRegistry(), $adapter->configurationStore(), $adapter->configurationStore()->read('global')?->get('choice')];
            if (count($received) === 1) {
                $adapter->requestSelection(new SelectionRequest('/inspect', 'Choose', [new SelectionOption('chosen', 'Continue')]));
            }
        });
        $terminal = new VirtualTerminal();
        EventLoop::queue(static fn () => $terminal->simulateInput("/inspect\r"));
        EventLoop::delay(0.05, static function () use ($terminal, $configuration, $configurations): void {
            $configuration->set('choice', 'after');
            $configurations->save($configuration);
            $terminal->simulateInput("\r");
        });
        EventLoop::delay(0.1, static fn () => $terminal->simulateInput("\x03"));

        Tui::make(new Agent(), $terminal, new Commands($command),
            agentFactoryRegistry: $registry, configurationStore: $configurations,
        )->run();

        self::assertSame([[$registry, $configurations, 'before'], [$registry, $configurations, 'after']], $received);
    }

    private function sessionCommands(Agent &$active): Commands
    {
        $observe = static function (CommandControlsAdapterInterface $adapter) use (&$active): void {
            $active = $adapter->agent();
        };

        return new Commands(array_map(
            static fn (CommandInterface $command): CommandInterface => new ObservedCommand($command, $observe),
            (new SessionCommandKit())->commands(),
        ));
    }

    /**
     * @param Closure(CommandControlsAdapterInterface<mixed>): void $run
     */
    private function commandThat(Closure $run): CommandInterface
    {
        return new class($run) implements CommandInterface {
            /** @param Closure(CommandControlsAdapterInterface<mixed>): void $run */
            public function __construct(private readonly Closure $run) {}

            public function name(): string
            {
                return '/inspect';
            }

            public function describe(): string
            {
                return 'Inspects the runtime composition.';
            }

            /** @param CommandControlsAdapterInterface<mixed> $adapter */
            public function run(CommandControlsAdapterInterface $adapter, CommandArguments $arguments): void
            {
                ($this->run)($adapter);
            }
        };
    }
}
