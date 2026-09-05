<?php

declare(strict_types=1);

namespace NeuronTui\Tests;

use Closure;
use NeuronInteraction\Command\Commands;
use NeuronAI\Agent\Agent;
use NeuronInteraction\Command\AbstractCommandKit;
use NeuronInteraction\Command\CommandArguments;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\HelpCommand;
use NeuronInteraction\Command\CommandAdapterInterface;
use NeuronTui\Tui;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class DuplicateCommandsTest extends TestCase
{
    public function testTheFirstDuplicateRunsForEveryWayCommandsCanBeAdded(): void
    {
        /**
         * @var array<string, Closure(Commands, CommandInterface, CommandInterface): Commands>
         */
        $mount = [
            'separate calls' => static fn (
                Commands $commands,
                CommandInterface $first,
                CommandInterface $second,
            ): Commands => $commands->addCommand($first)->addCommand($second),
            'one array' => static fn (
                Commands $commands,
                CommandInterface $first,
                CommandInterface $second,
            ): Commands => $commands->addCommand([$first, $second]),
            'one kit' => static fn (
                Commands $commands,
                CommandInterface $first,
                CommandInterface $second,
            ): Commands => $commands->addCommand(self::kit([$first, $second])),
            'array and kit' => static fn (
                Commands $commands,
                CommandInterface $first,
                CommandInterface $second,
            ): Commands => $commands->addCommand([
                $first,
                self::kit([$second]),
            ]),
        ];

        foreach ($mount as $form => $add) {
            $ran = [];
            $first = self::command(
                '/clear',
                'The first duplicate.',
                static function (
                    CommandAdapterInterface $adapter,
                    string $arguments,
                ) use (&$ran): void {
                    $ran[] = 'first';
                    $adapter->stop();
                },
            );
            $second = self::command(
                '/clear',
                'The second duplicate.',
                static function (
                    CommandAdapterInterface $adapter,
                    string $arguments,
                ) use (&$ran): void {
                    $ran[] = 'second';
                    $adapter->stop();
                },
            );
            $terminal = new VirtualTerminal();
            EventLoop::queue(
                static fn () => $terminal->simulateInput("/clear\r"),
            );

            (new Tui(new Agent(), $terminal, commands: $add(new Commands(), $first, $second)))->run();

            self::assertSame(['first'], $ran, $form);
        }
    }

    public function testSuggestionsAndHelpReceiveEveryDuplicateInAdditionOrder(): void
    {
        $descriptions = [
            'Added alone first.',
            'First member of the array.',
            'Second member of the array.',
            'First member of the kit.',
            'Second member of the kit.',
            'Direct member of the combination.',
            'Kit member of the combination.',
        ];
        $commands = array_map(
            static fn (string $description): CommandInterface => self::command(
                '/clear',
                $description,
            ),
            $descriptions,
        );
        $terminal = new VirtualTerminal(rows: 40);
        $suggestions = null;
        $help = null;

        EventLoop::delay(
            0.05,
            static fn () => $terminal->simulateInput('/clear'),
        );
        EventLoop::delay(
            0.1,
            static function () use ($terminal): void {
                $terminal->clearOutput();
                $terminal->simulateResize(80, 40);
            },
        );
        EventLoop::delay(
            0.15,
            static function () use (&$suggestions, $terminal): void {
                $suggestions = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->simulateInput("\x1b");
                $terminal->simulateInput("\x1b");
                $terminal->clearOutput();
                $terminal->simulateInput("/help\r");
            },
        );
        EventLoop::delay(
            0.3,
            static function () use (&$help, $terminal): void {
                $help = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                $terminal->simulateInput("\x03");
            },
        );

        $mounted = (new Commands())
            ->addCommand($commands[0])
            ->addCommand([$commands[1], $commands[2]])
            ->addCommand(self::kit([$commands[3], $commands[4]]))
            ->addCommand([$commands[5], self::kit([$commands[6]])])
            ->addCommand(new HelpCommand());
        (new Tui(new Agent(), $terminal, commands: $mounted))->run();

        self::assertIsString($suggestions);
        self::assertInOrder($descriptions, $suggestions);
        self::assertIsString($help);
        self::assertInOrder([
            ...$descriptions,
            'Lists what can be typed here.',
        ], $help);
        self::assertStringNotContainsString('Unknown Command', $help);
    }

    /**
     * @param list<CommandInterface> $commands
     * @return AbstractCommandKit<CommandInterface>
     */
    private static function kit(array $commands): AbstractCommandKit
    {
        return new
        /** @extends AbstractCommandKit<CommandInterface> */
        class($commands) extends AbstractCommandKit {
            /**
             * @param list<CommandInterface> $commands
             */
            public function __construct(
                private readonly array $commands,
            ) {
            }

            /**
             * @return list<CommandInterface>
             */
            protected function provide(): array
            {
                return $this->commands;
            }
        };
    }

    /**
     * @param Closure(CommandAdapterInterface<mixed>, string): void|null $run
     */
    private static function command(
        string $name,
        string $description,
        ?Closure $run = null,
    ): CommandInterface {
        return new class($name, $description, $run) implements CommandInterface {
            /**
             * @param Closure(CommandAdapterInterface<mixed>, string): void|null $run
             */
            public function __construct(
                private readonly string $commandName,
                private readonly string $description,
                private readonly ?Closure $run,
            ) {
            }

            public function name(): string
            {
                return $this->commandName;
            }

            public function describe(): string
            {
                return $this->description;
            }

            /** @param CommandAdapterInterface<mixed> $adapter */
            public function run(CommandAdapterInterface $adapter, CommandArguments $arguments): void
            {
                if ($this->run instanceof Closure) {
                    ($this->run)($adapter, $arguments->text);
                }
            }
        };
    }

    /**
     * @param list<string> $needles
     */
    private static function assertInOrder(array $needles, string $display): void
    {
        $previous = -1;

        foreach ($needles as $needle) {
            $position = strpos($display, $needle);
            self::assertNotFalse($position, $needle);
            self::assertGreaterThan($previous, $position, $needle);
            $previous = $position;
        }
    }
}
