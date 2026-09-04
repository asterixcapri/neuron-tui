<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Command;

use Closure;
use NeuronTui\Command\CommandArguments;
use NeuronTui\Command\CommandControlsInterface;
use NeuronTui\Command\CommandInterface;
use NeuronTui\Command\Commands;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CommandsTest extends TestCase
{
    public function testDispatchPreservesRawArgumentsAndMountingOrderWithFirstDuplicateWinning(): void
    {
        $received = null;
        $first = self::command('review', static function (CommandArguments $arguments) use (&$received): void {
            $received = $arguments;
        });
        $duplicate = self::command('review', static function (): void {
            self::fail('The duplicate must not execute.');
        });
        $last = self::command('last', static function (): void {
        });
        $commands = new Commands([$first, $duplicate, $last]);
        $arguments = new CommandArguments(" \tline one\n  line two \t");

        $execution = $commands->run('review', $arguments, self::controls());

        self::assertSame([$first, $duplicate, $last], $commands->all());
        self::assertSame($first, $commands->named('review'));
        self::assertSame($arguments, $received);
        self::assertSame('completed', $execution->status);
        self::assertSame('review', $execution->identifier);
        self::assertNull($execution->exception);
    }

    public function testUnknownIdentifierIsAnOutcomeAndSlashSyntaxIsNotInterpreted(): void
    {
        $commands = new Commands([self::command('review', static function (): void {
            self::fail('An unknown identifier must not execute.');
        })]);

        foreach (['missing', '/review'] as $identifier) {
            $execution = $commands->run($identifier, new CommandArguments(), self::controls());
            self::assertSame('unknown', $execution->status);
            self::assertSame($identifier, $execution->identifier);
            self::assertNull($execution->exception);
        }
    }

    public function testFailureKeepsOriginalExceptionAndDispatcherRemainsUsable(): void
    {
        $failure = new RuntimeException('Command failed');
        $commands = new Commands([
            self::command('broken', static function () use ($failure): void {
                throw $failure;
            }),
            self::command('healthy', static function (): void {
            }),
        ]);
        $controls = self::controls();
        $execution = $commands->run('broken', new CommandArguments(), $controls);

        self::assertSame('failed', $execution->status);
        self::assertSame('broken', $execution->identifier);
        self::assertSame($failure, $execution->exception);
        self::assertSame('completed', $commands->run('healthy', new CommandArguments(), $controls)->status);
    }

    /** @param Closure(CommandArguments): void $run */
    private static function command(string $name, Closure $run): CommandInterface
    {
        return new class($name, $run) implements CommandInterface {
            /** @param Closure(CommandArguments): void $run */
            public function __construct(private string $name, private Closure $run)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function describe(): string
            {
                return 'A test Command';
            }

            public function run(CommandControlsInterface $controls, CommandArguments $arguments): void
            {
                ($this->run)($arguments);
            }
        };
    }

    private static function controls(): CommandControlsInterface
    {
        return new FakeCommandControls();
    }
}
