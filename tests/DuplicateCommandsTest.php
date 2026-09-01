<?php

declare(strict_types=1);

namespace NeuronTui\Tests;

use Closure;
use NeuronAI\Agent\Agent;
use NeuronTui\Command\AbstractCommandKit;
use NeuronTui\Command\Command;
use NeuronTui\Command\Help;
use NeuronTui\Conversation\Controls;
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
         * @var array<string, Closure(Tui, Command, Command): Tui>
         */
        $mount = [
            'separate calls' => static fn (
                Tui $tui,
                Command $first,
                Command $second,
            ): Tui => $tui->addCommand($first)->addCommand($second),
            'one array' => static fn (
                Tui $tui,
                Command $first,
                Command $second,
            ): Tui => $tui->addCommand([$first, $second]),
            'one kit' => static fn (
                Tui $tui,
                Command $first,
                Command $second,
            ): Tui => $tui->addCommand(self::kit([$first, $second])),
            'array and kit' => static fn (
                Tui $tui,
                Command $first,
                Command $second,
            ): Tui => $tui->addCommand([
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
                    Controls $controls,
                    string $arguments,
                ) use (&$ran): void {
                    $ran[] = 'first';
                    $controls->stop();
                },
            );
            $second = self::command(
                '/clear',
                'The second duplicate.',
                static function (
                    Controls $controls,
                    string $arguments,
                ) use (&$ran): void {
                    $ran[] = 'second';
                    $controls->stop();
                },
            );
            $terminal = new VirtualTerminal();
            EventLoop::queue(
                static fn () => $terminal->simulateInput("/clear\r"),
            );

            $add(new Tui(new Agent(), $terminal), $first, $second)->run();

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
            static fn (string $description): Command => self::command(
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

        (new Tui(new Agent(), $terminal))
            ->addCommand($commands[0])
            ->addCommand([$commands[1], $commands[2]])
            ->addCommand(self::kit([$commands[3], $commands[4]]))
            ->addCommand([$commands[5], self::kit([$commands[6]])])
            ->addCommand(new Help())
            ->run();

        self::assertIsString($suggestions);
        self::assertInOrder($descriptions, $suggestions);
        self::assertIsString($help);
        self::assertInOrder([
            ...$descriptions,
            'Lists what can be typed here.',
        ], $help);
        self::assertStringNotContainsString('Unknown Slash command', $help);
    }

    /**
     * @param list<Command> $commands
     */
    private static function kit(array $commands): AbstractCommandKit
    {
        return new class($commands) extends AbstractCommandKit {
            /**
             * @param list<Command> $commands
             */
            public function __construct(
                private readonly array $commands,
            ) {
            }

            /**
             * @return list<Command>
             */
            protected function provide(): array
            {
                return $this->commands;
            }
        };
    }

    /**
     * @param Closure(Controls, string): void|null $run
     */
    private static function command(
        string $name,
        string $description,
        ?Closure $run = null,
    ): Command {
        return new class($name, $description, $run) implements Command {
            /**
             * @param Closure(Controls, string): void|null $run
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

            public function run(Controls $controls, string $arguments): void
            {
                if ($this->run instanceof Closure) {
                    ($this->run)($controls, $arguments);
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
