<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Tui;

use Generator;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronInteraction\Command\CommandArguments;
use NeuronInteraction\Command\ClearCommand;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\ResumeCommand;
use NeuronInteraction\Command\SelectionOption;
use NeuronInteraction\Command\SelectionRequest;
use NeuronInteraction\Command\CommandControlsInterface;
use NeuronInteraction\Session\Sessions;
use NeuronInteraction\Storage\FileStorage;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronTui\Tui;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class InputHistoryTest extends TestCase
{
    public function testCommandsAreRecordedBeforeTheyAreDispatched(): void
    {
        $provider = new class(
            new AssistantMessage('A slow answer.'),
        ) extends FakeAIProvider {
            protected function streamChunks(Message $response): Generator
            {
                \Amp\delay(0.3);
                yield new TextChunk('slow-stream', 'A slow answer.');

                return $response;
            }
        };
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $storage = new InMemoryStorage();
        $terminal = new VirtualTerminal(rows: 24);
        $command = new class() implements CommandInterface {
            /** @var list<string> */
            public array $arguments = [];

            public function name(): string
            {
                return 'probe';
            }

            public function describe(): string
            {
                return 'Record that the command ran.';
            }

            public function run(CommandControlsInterface $controls, CommandArguments $arguments): void
            {
                $this->arguments[] = $arguments->text;
            }
        };

        EventLoop::queue(
            static fn () => $terminal->simulateInput("/probe accepted\r"),
        );
        EventLoop::delay(
            0.03,
            static fn () => $terminal->simulateInput("/unknown\r"),
        );
        EventLoop::delay(
            0.06,
            static function () use ($terminal): void {
                $terminal->simulateInput("\x01\x0b");
                $terminal->simulateInput("A question\r");
            },
        );
        EventLoop::delay(
            0.1,
            static fn () => $terminal->simulateInput("/probe refused\r"),
        );
        EventLoop::delay(
            0.42,
            static function () use ($terminal): void {
                $terminal->simulateInput("\x01\x0b");
                $terminal->simulateInput(str_repeat("\x1b[A", 4));
                $terminal->simulateInput(" recalled\r");
            },
        );
        EventLoop::delay(
            0.5,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new Tui($agent, $terminal))
            ->setStorage($storage)
            ->addCommand($command)
            ->run();

        self::assertSame(['accepted', 'accepted recalled'], $command->arguments);
        self::assertSame(
            [
                '/probe accepted',
                '/unknown',
                'A question',
                '/probe refused',
                '/probe accepted recalled',
            ],
            $storage->read('input-history', 'entries')?->data,
        );
    }

    public function testAQueuedMessageIsRecallableWhileItRemainsQueued(): void
    {
        $provider = new class(
            new AssistantMessage('First answer.'),
            new AssistantMessage('Second answer.'),
        ) extends FakeAIProvider {
            private int $turn = 0;

            protected function streamChunks(Message $response): Generator
            {
                ++$this->turn;

                if ($this->turn === 1) {
                    \Amp\delay(0.3);
                }

                yield new TextChunk(
                    'queued-stream',
                    $response->getContent() ?? '',
                );

                return $response;
            }
        };
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $storage = new InMemoryStorage();
        $terminal = new VirtualTerminal(rows: 30);
        $whileQueued = null;

        EventLoop::queue(
            static fn () => $terminal->simulateInput("First question\r"),
        );
        EventLoop::delay(
            0.04,
            static fn () => $terminal->simulateInput("Second question\r"),
        );
        EventLoop::delay(
            0.08,
            static function () use ($terminal): void {
                $terminal->simulateInput("\x1b[A");
                $terminal->simulateInput('-recalled');
            },
        );
        EventLoop::delay(
            0.14,
            static function () use (&$whileQueued, $terminal): void {
                $whileQueued = $terminal->getOutput();
                $terminal->simulateInput("\x03");
            },
        );

        (new Tui($agent, $terminal))->setStorage($storage)->run();

        self::assertIsString($whileQueued);
        $whileQueued = AnsiUtils::stripAnsiCodes($whileQueued);
        self::assertStringContainsString('↳ Second question', $whileQueued);
        self::assertStringContainsString(
            '❯ Second question-recalled',
            $whileQueued,
        );
        self::assertSame(
            ['First question', 'Second question'],
            $storage->read('input-history', 'entries')?->data,
        );
        self::assertCount(1, $provider->getRecorded());
    }

    public function testClearingASessionKeepsItsInputHistoryAvailable(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('First answer.'),
            new AssistantMessage('Second answer.'),
        );
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $storage = new InMemoryStorage();
        $terminal = new VirtualTerminal(rows: 24);

        EventLoop::queue(
            static fn () => $terminal->simulateInput(
                "Remember across clear\r",
            ),
        );
        EventLoop::delay(
            0.3,
            static fn () => $terminal->simulateInput("/clear\r"),
        );
        EventLoop::delay(
            0.4,
            static function () use ($terminal): void {
                // Once every submission is recorded, the first Up recalls
                // `/clear`; the second reaches the input from its old Session.
                $terminal->simulateInput("\x1b[A\x1b[A");
                $terminal->simulateInput(" after clear\r");
            },
        );
        EventLoop::delay(
            0.75,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new Tui($agent, $terminal))
            ->setStorage($storage)
            ->addCommand(new ClearCommand())
            ->run();

        self::assertCount(2, $provider->getRecorded());
        self::assertSame(
            'Remember across clear after clear',
            $provider->getRecorded()[1]->messages[0]->getContent(),
        );
        self::assertCount(2, (new Sessions($storage))->list());
    }

    public function testResumingASessionKeepsItsInputHistoryAvailable(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('A new answer.'));
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $storage = new InMemoryStorage();
        $earlier = (new Sessions($storage))->start();
        $earlier->addMessage(new UserMessage('Earlier subject.'));
        $earlier->addMessage(new AssistantMessage('Earlier answer.'));
        $storage->write(
            'input-history',
            'entries',
            ['Remember across resume'],
        );
        $terminal = new VirtualTerminal(rows: 24);

        EventLoop::queue(
            static fn () => $terminal->simulateInput("/resume\r"),
        );
        EventLoop::delay(
            0.08,
            static fn () => $terminal->simulateInput("\r"),
        );
        EventLoop::delay(
            0.16,
            static function () use ($terminal): void {
                // The second Up reaches the pre-resume entry whether or not
                // the `/resume` submission itself is the newest entry.
                $terminal->simulateInput("\x1b[A\x1b[A");
                $terminal->simulateInput(" after resume\r");
            },
        );
        EventLoop::delay(
            0.4,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new Tui($agent, $terminal))
            ->setStorage($storage)
            ->addCommand(new ResumeCommand())
            ->run();

        self::assertCount(1, $provider->getRecorded());
        self::assertSame(
            'Remember across resume after resume',
            $provider->getRecorded()[0]->messages[2]->getContent(),
        );
    }

    public function testTuisShareInputHistoryThroughOneInMemoryStorage(): void
    {
        $storage = new InMemoryStorage();
        $firstProvider = new FakeAIProvider(
            new AssistantMessage('First answer.'),
        );
        $firstAgent = new Agent();
        $firstAgent->setAiProvider($firstProvider);
        $firstTerminal = new VirtualTerminal(rows: 24);

        EventLoop::queue(
            static fn () => $firstTerminal->simulateInput(
                "Remember between TUIs\r",
            ),
        );
        EventLoop::delay(
            0.2,
            static fn () => $firstTerminal->simulateInput("\x03"),
        );
        (new Tui($firstAgent, $firstTerminal))->setStorage($storage)->run();

        $secondProvider = new FakeAIProvider(
            new AssistantMessage('Second answer.'),
        );
        $secondAgent = new Agent();
        $secondAgent->setAiProvider($secondProvider);
        $secondTerminal = new VirtualTerminal(rows: 24);

        EventLoop::queue(static function () use ($secondTerminal): void {
            $secondTerminal->simulateInput("\x1b[A");
            $secondTerminal->simulateInput(" recalled\r");
        });
        EventLoop::delay(
            0.2,
            static fn () => $secondTerminal->simulateInput("\x03"),
        );
        (new Tui($secondAgent, $secondTerminal))->setStorage($storage)->run();

        self::assertSame(
            'Remember between TUIs recalled',
            $secondProvider->getRecorded()[0]->messages[0]->getContent(),
        );
    }

    public function testFileStorageRestoresInputHistoryInANewRuntime(): void
    {
        $directory = sys_get_temp_dir()
            . '/neuron-tui-input-history-'
            . bin2hex(random_bytes(6));

        try {
            $firstProvider = new FakeAIProvider(
                new AssistantMessage('First answer.'),
            );
            $firstAgent = new Agent();
            $firstAgent->setAiProvider($firstProvider);
            $firstTerminal = new VirtualTerminal(rows: 24);

            EventLoop::queue(
                static fn () => $firstTerminal->simulateInput(
                    "Remember after recreation\r",
                ),
            );
            EventLoop::delay(
                0.2,
                static fn () => $firstTerminal->simulateInput("\x03"),
            );
            (new Tui($firstAgent, $firstTerminal))
                ->setStorage(new FileStorage($directory))
                ->run();

            $secondProvider = new FakeAIProvider(
                new AssistantMessage('Second answer.'),
            );
            $secondAgent = new Agent();
            $secondAgent->setAiProvider($secondProvider);
            $secondTerminal = new VirtualTerminal(rows: 24);

            EventLoop::queue(static function () use ($secondTerminal): void {
                $secondTerminal->simulateInput("\x1b[A");
                $secondTerminal->simulateInput(" recalled\r");
            });
            EventLoop::delay(
                0.2,
                static fn () => $secondTerminal->simulateInput("\x03"),
            );
            (new Tui($secondAgent, $secondTerminal))
                ->setStorage(new FileStorage($directory))
                ->run();

            self::assertSame(
                'Remember after recreation recalled',
                $secondProvider->getRecorded()[0]->messages[0]->getContent(),
            );
        } finally {
            foreach (glob($directory . '/*/*') ?: [] as $path) {
                unlink($path);
            }

            foreach (glob($directory . '/*') ?: [] as $path) {
                rmdir($path);
            }

            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function testDefaultInputHistoryStaysInMemoryAndWritesNothingToDisk(): void
    {
        $workingDirectory = getcwd();
        self::assertIsString($workingDirectory);
        $before = scandir($workingDirectory);
        $provider = new FakeAIProvider(
            new AssistantMessage('First answer.'),
            new AssistantMessage('Second answer.'),
        );
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $terminal = new VirtualTerminal(rows: 24);

        EventLoop::queue(
            static fn () => $terminal->simulateInput("Kept in memory\r"),
        );
        EventLoop::delay(
            0.3,
            static function () use ($terminal): void {
                $terminal->simulateInput("\x1b[A");
                $terminal->simulateInput(" recalled\r");
            },
        );
        EventLoop::delay(
            0.65,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new Tui($agent, $terminal))->run();

        self::assertCount(2, $provider->getRecorded());
        self::assertSame(
            'Kept in memory recalled',
            $provider->getRecorded()[1]->messages[2]->getContent(),
        );
        self::assertDirectoryDoesNotExist($workingDirectory . '/.neuron');
        self::assertSame($before, scandir($workingDirectory));
    }

    public function testUpRecallsTheNewestSubmittedMessageAtItsEnd(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('An answer.'));
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $storage = new InMemoryStorage();
        $terminal = new VirtualTerminal(rows: 24);

        EventLoop::queue(
            static fn () => $terminal->simulateInput("  Remember me  \r"),
        );
        EventLoop::delay(
            0.12,
            static function () use ($terminal): void {
                $terminal->simulateInput("\x1b[A");
                $terminal->simulateInput('again');
                $terminal->simulateInput("\r");
            },
        );
        EventLoop::delay(
            0.35,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new Tui($agent, $terminal))->setStorage($storage)->run();

        self::assertSame(
            ['  Remember me  ', '  Remember me  again'],
            $storage->read('input-history', 'entries')?->data,
        );
    }

    public function testUpWithNoStoredInputsLeavesTheComposerEmpty(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('An answer.'));
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $storage = new InMemoryStorage();
        $terminal = new VirtualTerminal(rows: 24);

        EventLoop::queue(
            static function () use ($terminal): void {
                $terminal->simulateInput("\x1b[A");
                $terminal->simulateInput("Fresh message\r");
            },
        );
        EventLoop::delay(
            0.2,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new Tui($agent, $terminal))->setStorage($storage)->run();

        self::assertCount(1, $provider->getRecorded());
        self::assertSame(
            'Fresh message',
            $provider->getRecorded()[0]->messages[0]->getContent(),
        );
    }

    public function testInputHistoryMovesBothWaysAndPastNewestRestoresEmpty(): void
    {
        $fixture = $this->tuiWithInputHistory([
            'oldest',
            'middle',
            'newest',
        ]);

        EventLoop::queue(static function () use ($fixture): void {
            $fixture->terminal->simulateInput(str_repeat("\x1b[A", 4));
            $fixture->terminal->simulateInput(str_repeat("\x1b[B", 3));
            $fixture->terminal->simulateInput("After history\r");
        });
        EventLoop::delay(
            0.2,
            static fn () => $fixture->terminal->simulateInput("\x03"),
        );

        (new Tui($fixture->agent, $fixture->terminal))
            ->setStorage($fixture->storage)
            ->run();

        self::assertSame(
            'After history',
            $fixture->provider->getRecorded()[0]->messages[0]->getContent(),
        );
    }

    public function testEditingARecallMakesItAnOrdinaryDraft(): void
    {
        $fixture = $this->tuiWithInputHistory([
            'older',
            'newest',
        ]);

        EventLoop::queue(static function () use ($fixture): void {
            $fixture->terminal->simulateInput("\x1b[A");
            $fixture->terminal->simulateInput('-edited');
            $fixture->terminal->simulateInput("\x1b[A");
            $fixture->terminal->simulateInput('prefix-');
            $fixture->terminal->simulateInput("\r");
        });
        EventLoop::delay(
            0.2,
            static fn () => $fixture->terminal->simulateInput("\x03"),
        );

        (new Tui($fixture->agent, $fixture->terminal))
            ->setStorage($fixture->storage)
            ->run();

        self::assertSame(
            'prefix-newest-edited',
            $fixture->provider->getRecorded()[0]->messages[0]->getContent(),
        );
        self::assertSame(
            ['older', 'newest', 'prefix-newest-edited'],
            $fixture->storage->read('input-history', 'entries')?->data,
        );
    }

    public function testMultilineRecallPlacesTheCursorAtTheEnd(): void
    {
        $fixture = $this->tuiWithInputHistory([
            "first line\nsecond line",
        ]);

        EventLoop::queue(static function () use ($fixture): void {
            $fixture->terminal->simulateInput("\x1b[A");
            $fixture->terminal->simulateInput("!\r");
        });
        EventLoop::delay(
            0.2,
            static fn () => $fixture->terminal->simulateInput("\x03"),
        );

        (new Tui($fixture->agent, $fixture->terminal))
            ->setStorage($fixture->storage)
            ->run();

        self::assertSame(
            "first line\nsecond line!",
            $fixture->provider->getRecorded()[0]->messages[0]->getContent(),
        );
    }

    public function testArrowsKeepTheirEditorBehaviourInAMultilineDraft(): void
    {
        $fixture = $this->tuiWithInputHistory([
            'stored input',
        ]);

        EventLoop::queue(static function () use ($fixture): void {
            $fixture->terminal->simulateInput('first');
            $fixture->terminal->simulateInput("\x1b[13;2u");
            $fixture->terminal->simulateInput('second');
            $fixture->terminal->simulateInput("\x1b[A!");
            $fixture->terminal->simulateInput("\x1b[B?\r");
        });
        EventLoop::delay(
            0.2,
            static fn () => $fixture->terminal->simulateInput("\x03"),
        );

        (new Tui($fixture->agent, $fixture->terminal))
            ->setStorage($fixture->storage)
            ->run();

        self::assertSame(
            "first!\nsecond?",
            $fixture->provider->getRecorded()[0]->messages[0]->getContent(),
        );
    }

    public function testArrowsKeepTheirEditorBehaviourInAVisuallyWrappedDraft(): void
    {
        $fixture = $this->tuiWithInputHistory(
            ['stored input'],
            columns: 30,
        );
        $draft = str_repeat('wrapped ', 8);

        EventLoop::queue(static function () use ($fixture, $draft): void {
            $fixture->terminal->simulateInput($draft);
            $fixture->terminal->simulateInput("\x1b[A[");
            $fixture->terminal->simulateInput("\x1b[B]\r");
        });
        EventLoop::delay(
            0.2,
            static fn () => $fixture->terminal->simulateInput("\x03"),
        );

        (new Tui($fixture->agent, $fixture->terminal))
            ->setStorage($fixture->storage)
            ->run();

        self::assertSame(
            '[' . $draft . ']',
            $fixture->provider->getRecorded()[0]->messages[0]->getContent(),
        );
    }

    public function testSuggestionArrowsDoNotNavigateInputHistory(): void
    {
        $fixture = $this->tuiWithInputHistory([
            'stored input',
        ]);
        $display = null;

        EventLoop::queue(static function () use ($fixture): void {
            $fixture->terminal->simulateInput('/al');
            $fixture->terminal->simulateInput("\x1b[B\x1b[A\x1b[B");
        });
        EventLoop::delay(
            0.08,
            static function () use ($fixture): void {
                $fixture->terminal->clearOutput();
                $fixture->terminal->simulateResize(80, 24);
            },
        );
        EventLoop::delay(
            0.12,
            static function () use (&$display, $fixture): void {
                $display = AnsiUtils::stripAnsiCodes(
                    $fixture->terminal->getOutput(),
                );
                $fixture->terminal->simulateInput("\x03");
            },
        );

        (new Tui($fixture->agent, $fixture->terminal))
            ->setStorage($fixture->storage)
            ->addCommand([
                self::commandNamed('/alpha', 'The first suggestion.'),
                self::commandNamed('/album', 'The second suggestion.'),
            ])
            ->run();

        self::assertIsString($display);
        self::assertStringContainsString('→ /album', $display);
        self::assertStringContainsString('❯ /al', $display);
        self::assertStringNotContainsString('stored input', $display);
        $fixture->provider->assertNothingSent();
    }

    public function testPickerArrowsDoNotNavigateInputHistory(): void
    {
        $agent = new Agent();
        $agent->setAiProvider(new FakeAIProvider());
        $storage = new InMemoryStorage();
        $storage->write('input-history', 'entries', ['stored input']);
        $terminal = new VirtualTerminal(rows: 24);
        $command = new class() implements CommandInterface {
            public ?string $chosen = null;

            public function name(): string
            {
                return 'choose';
            }

            public function describe(): string
            {
                return 'Choose an option.';
            }

            public function run(CommandControlsInterface $controls, CommandArguments $arguments): void
            {
                if ($arguments->text !== '') {
                    $this->chosen = $arguments->text;

                    return;
                }

                $controls->requestSelection(new SelectionRequest($this->name(), 'Options', [
                    new SelectionOption('first', 'First option'),
                    new SelectionOption('last', 'Last option'),
                ]));
            }
        };

        EventLoop::queue(
            static fn () => $terminal->simulateInput("/choose\r"),
        );
        EventLoop::delay(
            0.04,
            static fn () => $terminal->simulateInput("\x1b[A\r"),
        );
        EventLoop::delay(
            0.1,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new Tui($agent, $terminal))
            ->setStorage($storage)
            ->addCommand($command)
            ->run();

        self::assertSame('last', $command->chosen);
    }

    public function testPageKeysNeverNavigateInputHistory(): void
    {
        $fixture = $this->tuiWithInputHistory([
            'stored input',
        ]);

        EventLoop::queue(static function () use ($fixture): void {
            $fixture->terminal->simulateInput("\x1b[5~\x1b[6~");
            $fixture->terminal->simulateInput("Fresh message\r");
        });
        EventLoop::delay(
            0.2,
            static fn () => $fixture->terminal->simulateInput("\x03"),
        );

        (new Tui($fixture->agent, $fixture->terminal))
            ->setStorage($fixture->storage)
            ->run();

        self::assertSame(
            'Fresh message',
            $fixture->provider->getRecorded()[0]->messages[0]->getContent(),
        );
    }

    public function testRecalledCommandsRestoreSuggestionsAfterAnEdit(): void
    {
        $fixture = $this->tuiWithInputHistory([
            '/probe',
        ]);
        $recalled = null;
        $edited = null;

        EventLoop::queue(
            static fn () => $fixture->terminal->simulateInput("\x1b[A"),
        );
        EventLoop::delay(
            0.06,
            static function () use ($fixture): void {
                $fixture->terminal->clearOutput();
                $fixture->terminal->simulateResize(80, 24);
            },
        );
        EventLoop::delay(
            0.1,
            static function () use (&$recalled, $fixture): void {
                $recalled = AnsiUtils::stripAnsiCodes(
                    $fixture->terminal->getOutput(),
                );
                $fixture->terminal->simulateInput("\x7f");
            },
        );
        EventLoop::delay(
            0.16,
            static function () use ($fixture): void {
                $fixture->terminal->clearOutput();
                $fixture->terminal->simulateResize(80, 24);
            },
        );
        EventLoop::delay(
            0.2,
            static function () use (&$edited, $fixture): void {
                $edited = AnsiUtils::stripAnsiCodes(
                    $fixture->terminal->getOutput(),
                );
                $fixture->terminal->simulateInput("\x03");
            },
        );

        (new Tui($fixture->agent, $fixture->terminal))
            ->setStorage($fixture->storage)
            ->addCommand(self::commandNamed('/probe', 'Runs the probe.'))
            ->run();

        self::assertIsString($recalled);
        self::assertStringContainsString('❯ /probe', $recalled);
        self::assertStringNotContainsString('Runs the probe.', $recalled);
        self::assertStringNotContainsString('suggesting ·', $recalled);
        self::assertIsString($edited);
        self::assertStringContainsString('❯ /prob', $edited);
        self::assertStringContainsString('Runs the probe.', $edited);
        self::assertStringContainsString('suggesting ·', $edited);
        $fixture->provider->assertNothingSent();
    }

    public function testReturningFromARecallToEmptyRestoresSuggestions(): void
    {
        $fixture = $this->tuiWithInputHistory([
            '/probe',
        ]);
        $display = null;

        EventLoop::queue(static function () use ($fixture): void {
            $fixture->terminal->simulateInput("\x1b[A\x1b[B");
            $fixture->terminal->simulateInput('/');
        });
        EventLoop::delay(
            0.08,
            static function () use ($fixture): void {
                $fixture->terminal->clearOutput();
                $fixture->terminal->simulateResize(80, 24);
            },
        );
        EventLoop::delay(
            0.12,
            static function () use (&$display, $fixture): void {
                $display = AnsiUtils::stripAnsiCodes(
                    $fixture->terminal->getOutput(),
                );
                $fixture->terminal->simulateInput("\x03");
            },
        );

        (new Tui($fixture->agent, $fixture->terminal))
            ->setStorage($fixture->storage)
            ->addCommand(self::commandNamed('/probe', 'Runs the probe.'))
            ->run();

        self::assertIsString($display);
        self::assertStringContainsString('❯ /', $display);
        self::assertStringContainsString('Runs the probe.', $display);
        self::assertStringContainsString('suggesting ·', $display);
        $fixture->provider->assertNothingSent();
    }

    /** @param list<string> $entries */
    private function tuiWithInputHistory(
        array $entries,
        int $columns = 80,
    ): InputHistoryTuiFixture {
        return new InputHistoryTuiFixture($entries, $columns);
    }

    private static function commandNamed(
        string $name,
        string $description,
    ): CommandInterface {
        return new class($name, $description) implements CommandInterface {
            public function __construct(
                private readonly string $commandName,
                private readonly string $description,
            ) {
            }

            public function name(): string
            {
                return ltrim($this->commandName, '/');
            }

            public function describe(): string
            {
                return $this->description;
            }

            public function run(CommandControlsInterface $controls, CommandArguments $arguments): void
            {
            }
        };
    }
}

/** @internal */
final readonly class InputHistoryTuiFixture
{
    public FakeAIProvider $provider;

    public Agent $agent;

    public InMemoryStorage $storage;

    public VirtualTerminal $terminal;

    /** @param list<string> $entries */
    public function __construct(array $entries, int $columns)
    {
        $this->provider = new FakeAIProvider(
            new AssistantMessage('An answer.'),
        );
        $this->agent = new Agent();
        $this->agent->setAiProvider($this->provider);
        $this->storage = new InMemoryStorage();
        $this->storage->write('input-history', 'entries', $entries);
        $this->terminal = new VirtualTerminal(columns: $columns, rows: 24);
    }
}
