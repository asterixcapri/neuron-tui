<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Command;

use NeuronAI\Agent\Agent;
use NeuronTui\Command\CommandArguments;
use NeuronTui\Command\CommandControls;
use NeuronTui\Command\CommandInterface;
use NeuronTui\Command\Commands;
use NeuronTui\Command\SelectionOption;
use NeuronTui\Command\SelectionRequest;
use PHPUnit\Framework\TestCase;

final class CommandControlsTest extends TestCase
{
    public function testAnOrdinaryCommandUsesSharedControlsWithoutATerminal(): void
    {
        $replacement = new Agent();
        $selection = new SelectionRequest('inspect', 'Pick one', [
            new SelectionOption('chosen-value', 'Visible label', 'Description'),
        ]);
        $command = new class($replacement, $selection) implements CommandInterface {
            public function __construct(private Agent $replacement, private SelectionRequest $selection)
            {
            }

            public function name(): string
            {
                return 'inspect';
            }

            public function describe(): string
            {
                return 'Exercises shared Command controls.';
            }

            public function run(CommandControls $controls, CommandArguments $arguments): void
            {
                $controls->say($controls->commands()->all()[0]->name());
                $controls->warn($arguments->text);
                $controls->agent()->setChatHistory($controls->sessions()->start());
                $controls->useAgent($this->replacement);
                $controls->promptAgent('A generated Agent prompt.');
                $controls->requestSelection($this->selection);
                $controls->say('The request has returned.');
                $controls->stop();
            }
        };
        $commands = new Commands([$command]);
        $controls = new FakeCommandControls($commands);
        $execution = $commands->run('inspect', new CommandArguments('A warning.'), $controls);

        self::assertSame('completed', $execution->status);
        self::assertSame(['inspect', 'The request has returned.'], $controls->notices);
        self::assertSame(['A warning.'], $controls->warnings);
        self::assertSame(['A generated Agent prompt.'], $controls->prompts);
        self::assertSame([$selection], $controls->selections);
        self::assertSame($commands, $controls->commands());
        self::assertSame($replacement, $controls->agent());
        self::assertTrue($controls->stopped);
    }
}
