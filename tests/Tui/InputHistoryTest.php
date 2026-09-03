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
use NeuronTui\Command\ClearCommand;
use NeuronTui\Command\CommandInterface;
use NeuronTui\Command\ResumeCommand;
use NeuronTui\Conversation\ChoiceOption;
use NeuronTui\Conversation\Controls;
use NeuronTui\Session\Sessions;
use NeuronTui\Storage\FileStorage;
use NeuronTui\Storage\InMemoryStorage;
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
                return '/probe';
            }

            public function describe(): string
            {
                return 'Record that the command ran.';
            }

            public function run(Controls $controls, string $arguments): void
            {
                $this->arguments[] = $arguments;
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

    public function testTuisShareHistoryThroughOneInMemoryStorage(): void
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

    public function testFileStorageRestoresHistoryInANewRuntime(): void
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

    public function testDefaultHistoryStaysInMemoryAndWritesNothingToDisk(): void
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

    public function testHistoryMovesBothWaysAndPastNewestRestoresEmpty(): void
    {
        [$provider, $storage, $terminal, $agent] = $this->tuiWithHistory([
            'oldest',
            'middle',
            'newest',
        ]);

        EventLoop::queue(static function () use ($terminal): void {
            $terminal->simulateInput(str_repeat("\x1b[A", 4));
            $terminal->simulateInput(str_repeat("\x1b[B", 3));
            $terminal->simulateInput("After history\r");
        });
        EventLoop::delay(
            0.2,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new Tui($agent, $terminal))->setStorage($storage)->run();

        self::assertSame(
            'After history',
            $provider->getRecorded()[0]->messages[0]->getContent(),
        );
    }

    public function testEditingARecallMakesItAnOrdinaryDraft(): void
    {
        [$provider, $storage, $terminal, $agent] = $this->tuiWithHistory([
            'older',
            'newest',
        ]);

        EventLoop::queue(static function () use ($terminal): void {
            $terminal->simulateInput("\x1b[A");
            $terminal->simulateInput('-edited');
            $terminal->simulateInput("\x1b[A");
            $terminal->simulateInput('prefix-');
            $terminal->simulateInput("\r");
        });
        EventLoop::delay(
            0.2,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new Tui($agent, $terminal))->setStorage($storage)->run();

        self::assertSame(
            'prefix-newest-edited',
            $provider->getRecorded()[0]->messages[0]->getContent(),
        );
        self::assertSame(
            ['older', 'newest', 'prefix-newest-edited'],
            $storage->read('input-history', 'entries')?->data,
        );
    }

    public function testMultilineRecallPlacesTheCursorAtTheEnd(): void
    {
        [$provider, $storage, $terminal, $agent] = $this->tuiWithHistory([
            "first line\nsecond line",
        ]);

        EventLoop::queue(static function () use ($terminal): void {
            $terminal->simulateInput("\x1b[A");
            $terminal->simulateInput("!\r");
        });
        EventLoop::delay(
            0.2,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new Tui($agent, $terminal))->setStorage($storage)->run();

        self::assertSame(
            "first line\nsecond line!",
            $provider->getRecorded()[0]->messages[0]->getContent(),
        );
    }

    public function testArrowsKeepTheirEditorBehaviourInAMultilineDraft(): void
    {
        [$provider, $storage, $terminal, $agent] = $this->tuiWithHistory([
            'stored input',
        ]);

        EventLoop::queue(static function () use ($terminal): void {
            $terminal->simulateInput('first');
            $terminal->simulateInput("\x1b[13;2u");
            $terminal->simulateInput('second');
            $terminal->simulateInput("\x1b[A!");
            $terminal->simulateInput("\x1b[B?\r");
        });
        EventLoop::delay(
            0.2,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new Tui($agent, $terminal))->setStorage($storage)->run();

        self::assertSame(
            "first!\nsecond?",
            $provider->getRecorded()[0]->messages[0]->getContent(),
        );
    }

    public function testArrowsKeepTheirEditorBehaviourInAVisuallyWrappedDraft(): void
    {
        [$provider, $storage, $terminal, $agent] = $this->tuiWithHistory(
            ['stored input'],
            columns: 30,
        );
        $draft = str_repeat('wrapped ', 8);

        EventLoop::queue(static function () use ($terminal, $draft): void {
            $terminal->simulateInput($draft);
            $terminal->simulateInput("\x1b[A[");
            $terminal->simulateInput("\x1b[B]\r");
        });
        EventLoop::delay(
            0.2,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new Tui($agent, $terminal))->setStorage($storage)->run();

        self::assertSame(
            '[' . $draft . ']',
            $provider->getRecorded()[0]->messages[0]->getContent(),
        );
    }

    public function testSuggestionArrowsDoNotNavigateInputHistory(): void
    {
        [$provider, $storage, $terminal, $agent] = $this->tuiWithHistory([
            'stored input',
        ]);
        $display = null;

        EventLoop::queue(static function () use ($terminal): void {
            $terminal->simulateInput('/al');
            $terminal->simulateInput("\x1b[B\x1b[A\x1b[B");
        });
        EventLoop::delay(
            0.08,
            static function () use ($terminal): void {
                $terminal->clearOutput();
                $terminal->simulateResize(80, 24);
            },
        );
        EventLoop::delay(
            0.12,
            static function () use (&$display, $terminal): void {
                $display = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->simulateInput("\x03");
            },
        );

        (new Tui($agent, $terminal))
            ->setStorage($storage)
            ->addCommand([
                self::commandNamed('/alpha', 'The first suggestion.'),
                self::commandNamed('/album', 'The second suggestion.'),
            ])
            ->run();

        self::assertIsString($display);
        self::assertStringContainsString('→ /album', $display);
        self::assertStringContainsString('❯ /al', $display);
        self::assertStringNotContainsString('stored input', $display);
        $provider->assertNothingSent();
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
                return '/choose';
            }

            public function describe(): string
            {
                return 'Choose an option.';
            }

            public function run(Controls $controls, string $arguments): void
            {
                $this->chosen = $controls->choose('Options', [
                    new ChoiceOption('first', 'First option'),
                    new ChoiceOption('last', 'Last option'),
                ]);
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
        [$provider, $storage, $terminal, $agent] = $this->tuiWithHistory([
            'stored input',
        ]);

        EventLoop::queue(static function () use ($terminal): void {
            $terminal->simulateInput("\x1b[5~\x1b[6~");
            $terminal->simulateInput("Fresh message\r");
        });
        EventLoop::delay(
            0.2,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new Tui($agent, $terminal))->setStorage($storage)->run();

        self::assertSame(
            'Fresh message',
            $provider->getRecorded()[0]->messages[0]->getContent(),
        );
    }

    public function testRecalledCommandsRestoreSuggestionsAfterAnEdit(): void
    {
        [$provider, $storage, $terminal, $agent] = $this->tuiWithHistory([
            '/probe',
        ]);
        $recalled = null;
        $edited = null;

        EventLoop::queue(
            static fn () => $terminal->simulateInput("\x1b[A"),
        );
        EventLoop::delay(
            0.06,
            static function () use ($terminal): void {
                $terminal->clearOutput();
                $terminal->simulateResize(80, 24);
            },
        );
        EventLoop::delay(
            0.1,
            static function () use (&$recalled, $terminal): void {
                $recalled = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->simulateInput("\x7f");
            },
        );
        EventLoop::delay(
            0.16,
            static function () use ($terminal): void {
                $terminal->clearOutput();
                $terminal->simulateResize(80, 24);
            },
        );
        EventLoop::delay(
            0.2,
            static function () use (&$edited, $terminal): void {
                $edited = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->simulateInput("\x03");
            },
        );

        (new Tui($agent, $terminal))
            ->setStorage($storage)
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
        $provider->assertNothingSent();
    }

    public function testReturningFromARecallToEmptyRestoresSuggestions(): void
    {
        [$provider, $storage, $terminal, $agent] = $this->tuiWithHistory([
            '/probe',
        ]);
        $display = null;

        EventLoop::queue(static function () use ($terminal): void {
            $terminal->simulateInput("\x1b[A\x1b[B");
            $terminal->simulateInput('/');
        });
        EventLoop::delay(
            0.08,
            static function () use ($terminal): void {
                $terminal->clearOutput();
                $terminal->simulateResize(80, 24);
            },
        );
        EventLoop::delay(
            0.12,
            static function () use (&$display, $terminal): void {
                $display = AnsiUtils::stripAnsiCodes(
                    $terminal->getOutput(),
                );
                $terminal->simulateInput("\x03");
            },
        );

        (new Tui($agent, $terminal))
            ->setStorage($storage)
            ->addCommand(self::commandNamed('/probe', 'Runs the probe.'))
            ->run();

        self::assertIsString($display);
        self::assertStringContainsString('❯ /', $display);
        self::assertStringContainsString('Runs the probe.', $display);
        self::assertStringContainsString('suggesting ·', $display);
        $provider->assertNothingSent();
    }

    /**
     * @param list<string> $entries
     *
     * @return array{FakeAIProvider, InMemoryStorage, VirtualTerminal, Agent}
     */
    private function tuiWithHistory(
        array $entries,
        int $columns = 80,
    ): array {
        $provider = new FakeAIProvider(new AssistantMessage('An answer.'));
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $storage = new InMemoryStorage();
        $storage->write('input-history', 'entries', $entries);

        return [
            $provider,
            $storage,
            new VirtualTerminal(columns: $columns, rows: 24),
            $agent,
        ];
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
                return $this->commandName;
            }

            public function describe(): string
            {
                return $this->description;
            }

            public function run(Controls $controls, string $arguments): void
            {
            }
        };
    }
}
