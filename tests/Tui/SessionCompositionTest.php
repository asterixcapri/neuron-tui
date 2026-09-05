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
use NeuronInteraction\Session\Sessions;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronTui\Tui;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class SessionCompositionTest extends TestCase
{
    public function testManagedConversationsAndLaterTurnsCanBeClearedAndResumed(): void
    {
        foreach (['default', 'preselected'] as $composition) {
            $sessions = $composition === 'default' ? null : new Sessions(new InMemoryStorage());
            $initial = $sessions !== null
                ? $sessions->start()
                : new InMemoryChatHistory();
            $initial->addMessage(new UserMessage('Initial subject'));
            $initial->addMessage(new AssistantMessage('Initial answer'));
            $selectedKey = null;
            if ($sessions !== null) {
                $selectedKey = $sessions->summaries()[0]->key;
                $initial = $sessions->resume($selectedKey);
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
        $sessions = new Sessions(new InMemoryStorage());
        $sessions->start()->addMessage(new UserMessage('Stored subject'));
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
        foreach ([false, true] as $supplySessions) {
            foreach ([false, true] as $supplyInputs) {
                $terminal = new VirtualTerminal();
                $inputs = $supplyInputs ? new InputHistory(new InMemoryStorage()) : null;
                EventLoop::queue(static fn () => $terminal->simulateInput("/help\r"));
                EventLoop::delay(0.06, static fn () => $terminal->simulateInput("\x03"));

                (new Tui(
                    new Agent(),
                    $terminal,
                    sessions: $supplySessions ? new Sessions(new InMemoryStorage()) : null,
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
        foreach ([false, true] as $supplySessions) {
            foreach ([false, true] as $supplyInputs) {
                $sessions = $supplySessions ? new Sessions(new InMemoryStorage()) : null;
                $inputs = $supplyInputs ? new InputHistory(new InMemoryStorage()) : null;
                $received = [];
                $command = $this->commandThat(
                    static function (CommandAdapterInterface $adapter) use (&$received): void {
                        $received[] = [$adapter->commands(), $adapter->sessions()];
                        if (count($received) === 1) {
                            $adapter->sessions()->start()->addMessage(new \NeuronAI\Chat\Messages\UserMessage('Kept by this module'));
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

    public function testRuntimePreservesExternalHistoryWithoutRegisteringItInSessions(): void
    {
        foreach ([false, true] as $supplySessions) {
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

            Tui::make($agent, $terminal, commands: new Commands([$command, new ResumeCommand()]), sessions: $supplySessions ? new Sessions($storage) : null)->run();

            self::assertCount(2, $received);
            self::assertInstanceOf(Sessions::class, $received[0]);
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
