<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Command;

use NeuronAI\Chat\Messages\UserMessage;
use NeuronTui\Command\CommandArguments;
use NeuronTui\Command\CommandControlsInterface;
use NeuronTui\Command\CommandInterface;
use NeuronTui\Command\Commands;
use NeuronTui\Command\ResumeCommand;
use NeuronTui\Command\SelectionOption;
use NeuronTui\Command\SelectionRequest;
use PHPUnit\Framework\TestCase;

final class SelectionTest extends TestCase
{
    public function testSelectionRequestSerializesOrderedOptionsAndReceivesTheValueInANewInvocation(): void
    {
        $request = new SelectionRequest('choose', 'Pick a value', [
            new SelectionOption('007', 'Visible label', 'Detailed description'),
            new SelectionOption(' raw value ', 'Another label'),
        ]);
        $command = new class($request) implements CommandInterface {
            public function __construct(private SelectionRequest $request)
            {
            }

            public function name(): string
            {
                return 'choose';
            }

            public function describe(): string
            {
                return 'Select a value.';
            }

            public function run(CommandControlsInterface $controls, CommandArguments $arguments): void
            {
                if ($arguments->text === '') {
                    $controls->requestSelection($this->request);
                    $controls->say('First invocation finished.');

                    return;
                }

                $controls->say($arguments->text);
            }
        };
        $commands = new Commands([$command]);
        $first = new FakeCommandControls($commands);

        self::assertSame('completed', $commands->run('choose', new CommandArguments(), $first)->status);
        self::assertSame(['First invocation finished.'], $first->notices);
        self::assertSame([$request], $first->selections);
        self::assertEquals([
            'command' => 'choose',
            'prompt' => 'Pick a value',
            'options' => [
                ['value' => '007', 'label' => 'Visible label', 'description' => 'Detailed description'],
                ['value' => ' raw value ', 'label' => 'Another label', 'description' => null],
            ],
            'description' => null,
        ], json_decode(json_encode($request, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));

        // A later Adapter invocation has fresh controls, without hidden selection state.
        $second = new FakeCommandControls($commands);
        $execution = $commands->run($request->command, new CommandArguments($request->options[1]->value), $second);

        self::assertSame('completed', $execution->status);
        self::assertSame([' raw value '], $second->notices);
        self::assertSame([], $second->selections);
    }

    public function testResumeRequestsSelectionThenInstallsTheChosenHistoryOnlyOnTheSecondInvocation(): void
    {
        $commands = new Commands([new ResumeCommand('return')]);
        $controls = new FakeCommandControls($commands);
        $stored = $controls->sessions()->start();
        $stored->addMessage(new UserMessage('Stored subject'));
        $active = $controls->sessions()->start();
        $controls->agent()->setChatHistory($active);
        $session = $controls->sessions()->list()[0];

        $first = $commands->run('return', new CommandArguments(), $controls);

        self::assertSame('completed', $first->status);
        self::assertSame($active, $controls->agent()->getChatHistory());
        self::assertCount(1, $controls->selections);
        $request = $controls->selections[0];
        self::assertSame('return', $request->command);
        self::assertSame($session->key, $request->options[0]->value);
        self::assertSame('Stored subject', $request->options[0]->label);
        self::assertNotNull($request->options[0]->description);

        $second = $commands->run('return', new CommandArguments($request->options[0]->value), $controls);

        self::assertSame('completed', $second->status);
        self::assertSame('Stored subject', $controls->agent()->getChatHistory()->getMessages()[0]->getContent());
        self::assertCount(1, $controls->selections);
    }

    public function testResumeWithAKeyNeedsNoPriorSelectionAndUnknownKeysFailNormally(): void
    {
        $commands = new Commands([new ResumeCommand()]);
        $controls = new FakeCommandControls($commands);
        $controls->sessions()->start()->addMessage(new UserMessage('Direct resume'));
        $key = $controls->sessions()->list()[0]->key;

        self::assertSame('completed', $commands->run('resume', new CommandArguments($key), $controls)->status);
        self::assertSame('Direct resume', $controls->agent()->getChatHistory()->getMessages()[0]->getContent());
        self::assertSame([], $controls->selections);

        $history = $controls->agent()->getChatHistory();
        self::assertSame('failed', $commands->run('resume', new CommandArguments('unknown'), $controls)->status);
        self::assertSame($history, $controls->agent()->getChatHistory());
    }
}
