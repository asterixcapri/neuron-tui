<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Tui;

use Closure;
use LogicException;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronTui\Command\CommandArguments;
use NeuronTui\Command\ClearCommand;
use NeuronTui\Command\CommandInterface;
use NeuronTui\Command\ConcurrentCommandInterface;
use NeuronTui\Command\ResumeCommand;
use NeuronTui\Command\SessionKit;
use NeuronTui\Conversation\ConcurrentControls;
use NeuronTui\Conversation\Controls;
use NeuronTui\Session\Sessions;
use NeuronTui\Session\StorageChatHistory;
use NeuronTui\Storage\InMemoryStorage;
use NeuronTui\Tui;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class SessionCompositionTest extends TestCase
{
    public function testStorageConfigurationIsFluentAndFreezesOnRun(): void
    {
        $terminal = new VirtualTerminal();
        $tui = Tui::make(new Agent(), $terminal);
        $storage = new InMemoryStorage();
        $failure = null;

        self::assertSame($tui, $tui->setStorage($storage));

        EventLoop::delay(
            0.04,
            static function () use ($tui, $terminal, &$failure): void {
                try {
                    $tui->setStorage(new InMemoryStorage());
                } catch (LogicException $exception) {
                    $failure = $exception;
                }

                $terminal->simulateInput("\x03");
            },
        );

        $tui->run();

        self::assertInstanceOf(LogicException::class, $failure);
        self::assertSame(
            'A TUI instance can only be configured and run once.',
            $failure->getMessage(),
        );
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
            static function (Controls $controls) use (&$received): void {
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

        Tui::make($agent, $terminal)
            ->setStorage($storage)
            ->addCommand($command)
            ->run();

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

    public function testOnlySettledControlsExposeSessions(): void
    {
        self::assertContains('sessions', get_class_methods(Controls::class));
        self::assertNotContains(
            'sessions',
            get_class_methods(ConcurrentControls::class),
        );
    }

    public function testSessionCommandsNeedNoParallelSessionDependency(): void
    {
        self::assertSame('clear', (new ClearCommand())->name());
        self::assertSame('wipe', (new ClearCommand('wipe'))->name());
        self::assertSame('resume', (new ResumeCommand())->name());
        self::assertSame('return', (new ResumeCommand('return'))->name());

        self::assertSame(
            [ClearCommand::class, ResumeCommand::class],
            array_map(
                static fn (
                    CommandInterface|ConcurrentCommandInterface $command,
                ): string => $command::class,
                (new SessionKit())->commands(),
            ),
        );
    }

    /**
     * @param Closure(Controls): void $run
     */
    private function commandThat(Closure $run): CommandInterface
    {
        return new class($run) implements CommandInterface {
            /** @param Closure(Controls): void $run */
            public function __construct(private readonly Closure $run) {}

            public function name(): string
            {
                return 'inspect';
            }

            public function describe(): string
            {
                return 'Inspects the runtime composition.';
            }

            public function run(Controls $controls, CommandArguments $arguments): void
            {
                ($this->run)($controls);
            }
        };
    }
}
