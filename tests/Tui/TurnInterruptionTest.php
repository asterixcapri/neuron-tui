<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Tui;

use Generator;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Testing\FakeAIProvider;
use NeuronInteraction\Command\CommandAdapterInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\ConcurrentCommandInterface;
use NeuronInteraction\Command\HelpCommand;
use NeuronInteraction\Command\Selection;
use NeuronInteraction\Command\SelectionOption;
use NeuronTui\Tui;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use RuntimeException;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

use function Amp\delay;

final class TurnInterruptionTest extends TestCase
{
    /** @return iterable<string, array{bool, bool}> */
    public static function providerFailures(): iterable
    {
        yield 'before text with waiting input' => [false, true];
        yield 'after text with waiting input' => [true, true];
        yield 'before text without waiting input' => [false, false];
        yield 'after text without waiting input' => [true, false];
    }

    #[DataProvider('providerFailures')]
    public function testProviderFailureAfterEscapeRetainsTheInterruptedTurnAndAdvancesOnce(bool $partial, bool $queued): void
    {
        $terminal = new VirtualTerminal(columns: 140, rows: 40);
        $provider = new class($terminal, $partial, $queued) extends FakeAIProvider {
            public function __construct(
                private readonly VirtualTerminal $terminal,
                private readonly bool $partial,
                private readonly bool $queued,
            ) {
                parent::__construct(new AssistantMessage('First answer.'), new AssistantMessage('Second answer.'), new AssistantMessage('Third answer.'));
            }

            protected function streamChunks(Message $response): Generator
            {
                if ($response->getContent() !== 'First answer.') {
                    return yield from parent::streamChunks($response);
                }

                if ($this->partial) {
                    yield new TextChunk('failure', 'Partial.');
                }

                $this->terminal->simulateInput(($this->queued ? "Second question\rThird question\r" : '') . "\x1b\x1b\x1b");
                delay(0.04);

                throw new RuntimeException('Provider connection dropped after Escape.');
            }
        };
        $agent = new Agent();
        $agent->setAiProvider($provider);
        EventLoop::queue(static fn () => $terminal->simulateInput("First question\r"));
        EventLoop::delay(0.3, static fn () => $terminal->simulateInput("\x03"));

        (new Tui($agent, terminal: $terminal))->run();

        $messages = $agent->getChatHistory()->getMessages();
        $expected = $partial ? ['First question', 'Partial.'] : ['First question'];

        if ($queued) {
            array_push($expected, 'Second question', 'Second answer.', 'Third question', 'Third answer.');
        }

        self::assertSame($expected, array_map(static fn (Message $message): ?string => $message->getContent(), $messages));
        self::assertSame('interrupted', $messages[$partial ? 1 : 0]->getMetadata('stop_reason'));
        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString('Turn interrupted.', $display);
        self::assertStringContainsString('RuntimeException: Provider connection dropped after Escape.', $display);
        self::assertStringContainsString('ready · Enter sends', $display);
        $provider->assertCallCount($queued ? 3 : 1);

        if ($queued) {
            self::assertSame(array_slice($messages, 0, $partial ? 3 : 2), $provider->getRecorded()[1]->messages);
            self::assertSame(array_slice($messages, 0, $partial ? 5 : 4), $provider->getRecorded()[2]->messages);
        }
    }

    public function testPickerConsumesFirstEscapeBeforeTurnInterruption(): void
    {
        $terminal = new VirtualTerminal(columns: 120, rows: 30);
        $provider = new class(new AssistantMessage('First. Second. Hidden.')) extends FakeAIProvider {
            protected function streamChunks(Message $response): Generator
            {
                yield new TextChunk('picker', 'First.');
                delay(0.14);
                yield new TextChunk('picker', ' Second.');
                delay(0.14);
                yield new TextChunk('picker', ' Hidden.');

                return $response;
            }
        };
        $command = new class implements ConcurrentCommandInterface {
            public function name(): string
            {
                return '/choose';
            }

            public function describe(): string
            {
                return 'Choose an item.';
            }

            /** @param CommandAdapterInterface<mixed> $adapter */
            public function run(CommandAdapterInterface $adapter, string $value): void
            {
                $adapter->requestSelection(new Selection('/choose', 'Choose an item', [new SelectionOption('one', 'One')]));
            }
        };
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $afterFirstEscape = '';
        EventLoop::queue(static fn () => $terminal->simulateInput("Question\r"));
        EventLoop::delay(0.07, static fn () => $terminal->simulateInput("/choose\r"));
        EventLoop::delay(0.12, static fn () => $terminal->simulateInput("\x1b"));
        EventLoop::delay(0.29, static function () use ($terminal, &$afterFirstEscape): void {
            $afterFirstEscape = AnsiUtils::stripAnsiCodes($terminal->getOutput());
            $terminal->simulateInput("\x1b");
        });
        EventLoop::delay(0.55, static fn () => $terminal->simulateInput("\x03"));

        (new Tui($agent, terminal: $terminal, commands: new Commands($command)))->run();

        self::assertStringContainsString('Choose an item', $afterFirstEscape);
        self::assertStringContainsString('First. Second.', $afterFirstEscape);
        self::assertStringNotContainsString('Interruption requested', $afterFirstEscape);
        $messages = $agent->getChatHistory()->getMessages();
        self::assertCount(2, $messages);
        self::assertSame('First. Second.', $messages[1]->getContent());
        self::assertSame('interrupted', $messages[1]->getMetadata('stop_reason'));
    }

    public function testEscapeRetainsPartialTextAdvancesFifoAndPreservesDraftEditing(): void
    {
        $terminal = new VirtualTerminal(columns: 120, rows: 40);
        $provider = new class extends FakeAIProvider {
            public function __construct()
            {
                parent::__construct(
                    new AssistantMessage('First. Hidden.'),
                    new AssistantMessage('Second answer.'),
                    new AssistantMessage('Third answer.'),
                    new AssistantMessage('Draft answer.'),
                );
            }

            protected function streamChunks(Message $response): Generator
            {
                if ($response->getContent() === 'First. Hidden.') {
                    yield new TextChunk('first', 'First.');
                    delay(0.24);
                    yield new TextChunk('first', ' Hidden.');

                    return $response;
                }

                yield from parent::streamChunks($response);

                return $response;
            }
        };
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $pending = '';
        EventLoop::queue(static fn () => $terminal->simulateInput("First question\r"));
        EventLoop::delay(0.1, static fn () => $terminal->simulateInput("Second question\rThird question\rDraft\x1b[D\x1b"));
        EventLoop::delay(0.15, static function () use ($terminal, &$pending): void {
            $pending = AnsiUtils::stripAnsiCodes($terminal->getOutput());
            $terminal->simulateInput("\x1b\x1b");
        });
        EventLoop::delay(0.45, static fn () => $terminal->simulateInput("!\r"));
        EventLoop::delay(0.65, static fn () => $terminal->simulateInput("\x03"));

        (new Tui($agent, terminal: $terminal))->run();

        self::assertStringContainsString('Interruption requested', $pending);
        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString('Turn interrupted.', $display);
        self::assertStringNotContainsString('Hidden.', $display);
        self::assertSame([
            'First question', 'First.', 'Second question', 'Second answer.',
            'Third question', 'Third answer.', 'Draf!t', 'Draft answer.',
        ], array_map(static fn (Message $message): ?string => $message->getContent(), $agent->getChatHistory()->getMessages()));
        self::assertSame('interrupted', $agent->getChatHistory()->getMessages()[1]->getMetadata('stop_reason'));
        $provider->assertCallCount(4);
        self::assertSame([
            'First question', 'First.', 'Second question',
        ], array_map(static fn (Message $message): ?string => $message->getContent(), $provider->getRecorded()[1]->messages));
    }

    public function testEscapeBeforeFirstTextRetainsOnlyTheUserAndReturnsReady(): void
    {
        $terminal = new VirtualTerminal(columns: 120);
        $provider = new class(new AssistantMessage('Unseen.')) extends FakeAIProvider {
            protected function streamChunks(Message $response): Generator
            {
                delay(0.12);
                yield new TextChunk('unseen', 'Unseen.');

                return $response;
            }
        };
        $agent = new Agent();
        $agent->setAiProvider($provider);
        EventLoop::queue(static fn () => $terminal->simulateInput("Question\r"));
        EventLoop::delay(0.04, static fn () => $terminal->simulateInput("draft\x1b"));
        EventLoop::delay(0.22, static fn () => $terminal->simulateInput("\x03"));

        (new Tui($agent, terminal: $terminal))->run();

        $history = $agent->getChatHistory()->getMessages();
        self::assertCount(1, $history);
        self::assertSame('Question', $history[0]->getContent());
        self::assertSame('interrupted', $history[0]->getMetadata('stop_reason'));
        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString('Turn interrupted.', $display);
        self::assertStringContainsString('ready · Enter sends', $display);
        self::assertStringContainsString('draft', $display);
        self::assertStringNotContainsString('Unseen.', $display);
        self::assertStringNotContainsString('Empty response.', $display);
    }

    public function testSuggestionsConsumeFirstEscapeAndSecondEscapeInterrupts(): void
    {
        $terminal = new VirtualTerminal(columns: 120);
        $provider = new class(new AssistantMessage('First. Second. Hidden.')) extends FakeAIProvider {
            protected function streamChunks(Message $response): Generator
            {
                yield new TextChunk('suggestions', 'First.');
                delay(0.1);
                yield new TextChunk('suggestions', ' Second.');
                delay(0.12);
                yield new TextChunk('suggestions', ' Hidden.');

                return $response;
            }
        };
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $afterFirstEscape = '';
        EventLoop::queue(static fn () => $terminal->simulateInput("Question\r"));
        EventLoop::delay(0.04, static fn () => $terminal->simulateInput("/h\x1b"));
        EventLoop::delay(0.14, static function () use ($terminal, &$afterFirstEscape): void {
            $afterFirstEscape = AnsiUtils::stripAnsiCodes($terminal->getOutput());
            $terminal->simulateInput("\x1b");
        });
        EventLoop::delay(0.3, static fn () => $terminal->simulateInput("\x03"));

        (new Tui($agent, terminal: $terminal, commands: new Commands(new HelpCommand())))->run();

        self::assertStringContainsString('First. Second.', $afterFirstEscape);
        self::assertStringNotContainsString('Interruption requested', $afterFirstEscape);
        $history = $agent->getChatHistory()->getMessages();
        self::assertCount(2, $history);
        self::assertSame('First. Second.', $history[1]->getContent());
        self::assertSame('interrupted', $history[1]->getMetadata('stop_reason'));
    }
}
