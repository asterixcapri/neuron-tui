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
use NeuronInteraction\Command\CommandControlsInterface;
use NeuronInteraction\Session\Sessions;
use NeuronInteraction\Session\StorageChatHistory;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronTui\Tui;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class SessionCompositionTest extends TestCase
{
    public function testInitialConversationAndLaterTurnCanBeClearedAndResumed(): void
    {
        foreach (['default', 'supplied', 'preselected'] as $composition) {
            $sessions = $composition === 'default' ? null : new Sessions(new InMemoryStorage());
            $initial = $sessions !== null && $composition === 'preselected'
                ? $sessions->start()
                : new InMemoryChatHistory();
            $initial->addMessage(new UserMessage('Initial subject'));
            $initial->addMessage(new AssistantMessage('Initial answer'));
            $selectedKey = null;
            if ($sessions !== null && $composition === 'preselected') {
                $selectedKey = $sessions->list()[0]->key;
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
            self::assertCount(4, $beforeClear);
            self::assertSame('Generated continuation', $beforeClear[3]->getContent());
            self::assertSame([], $afterClear);
            self::assertEquals($beforeClear, $agent->getChatHistory()->getMessages());
            $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
            self::assertStringContainsString('Initial subject', $display);
            self::assertStringContainsString('Generated continuation', $display);
            if ($sessions !== null) {
                self::assertCount(1, $sessions->list());
                if ($selectedKey !== null) {
                    self::assertSame($selectedKey, $sessions->list()[0]->key);
                }
            }
        }
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
                    static function (CommandControlsInterface $controls) use (&$received): void {
                        $received[] = [$controls->commands(), $controls->sessions()];
                        if (count($received) === 1) {
                            $controls->sessions()->start()->addMessage(new \NeuronAI\Chat\Messages\UserMessage('Kept by this module'));
                        } else {
                            self::assertCount(1, $controls->sessions()->list());
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

    public function testRuntimeStartsOneSessionAndSharesItWithCommands(): void
    {
        $storage = new InMemoryStorage();
        $previous = new InMemoryChatHistory();
        $agent = new Agent();
        $agent->setChatHistory($previous);
        $terminal = new VirtualTerminal();
        $received = [];
        $command = $this->commandThat(
            static function (CommandControlsInterface $controls) use (&$received): void {
                $received[] = $controls->sessions();
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

        Tui::make($agent, $terminal, commands: new Commands($command), sessions: new Sessions($storage))->run();

        self::assertCount(2, $received);
        self::assertInstanceOf(Sessions::class, $received[0]);
        self::assertSame($received[0], $received[1]);
        self::assertNotSame($previous, $agent->getChatHistory());
        self::assertInstanceOf(
            StorageChatHistory::class,
            $agent->getChatHistory(),
        );
        self::assertSame([], $agent->getChatHistory()->getMessages());

        $entries = iterator_to_array($storage->entries('sessions'));
        self::assertCount(1, $entries);
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
     * @param Closure(CommandControlsInterface): void $run
     */
    private function commandThat(Closure $run): CommandInterface
    {
        return new class($run) implements CommandInterface {
            /** @param Closure(CommandControlsInterface): void $run */
            public function __construct(private readonly Closure $run) {}

            public function name(): string
            {
                return '/inspect';
            }

            public function describe(): string
            {
                return 'Inspects the runtime composition.';
            }

            public function run(CommandControlsInterface $controls, CommandArguments $arguments): void
            {
                ($this->run)($controls);
            }
        };
    }
}
