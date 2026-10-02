<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Turn;

use Generator;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronTui\Tests\Tools\CallbackTool;
use NeuronTui\Turn\TurnRenderer;
use NeuronTui\View\ConversationView;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Tui\Terminal\ScreenBuffer;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

use function substr_count;

final class TurnRendererTest extends TestCase
{
    public function testReusedRendererDoesNotCarryTextOrToolActivityIntoAnEmptyTurn(): void
    {
        $terminal = new VirtualTerminal(rows: 40);
        $view = new ConversationView($terminal, 'Neuron AI', 'Conversation');
        $renderer = new TurnRenderer($view);
        $tool = (new CallbackTool('lookup'))->setCallId('lookup-call');

        self::assertTrue($renderer->run($this->stream([
            new TextChunk('first', 'First answer.'),
            new ToolCallChunk('first', $tool->call()),
        ])));
        self::assertTrue($renderer->run($this->stream([])));
        self::assertTrue($renderer->run($this->stream([new TextChunk('blank', " \n\t")])));

        $display = $this->screen($view, $terminal);
        self::assertStringContainsString('First answer.', $display);
        self::assertStringContainsString('lookup', $display);
        self::assertSame(2, substr_count($display, 'Empty response.'));
    }

    public function testWhitespaceBetweenChunksIsPreservedAndMessageChangesCreateSeparateEntries(): void
    {
        $terminal = new VirtualTerminal(rows: 40);
        $view = new ConversationView($terminal, 'Neuron AI', 'Conversation');
        $renderer = new TurnRenderer($view);

        self::assertTrue($renderer->run($this->stream([
            new TextChunk('first', 'Hello'),
            new TextChunk('first', ' '),
            new TextChunk('first', 'world.'),
            new TextChunk('second', 'Next answer.'),
        ])));

        $display = $this->screen($view, $terminal);
        self::assertStringContainsString('● Hello world.', $display);
        self::assertStringContainsString('● Next answer.', $display);
        self::assertStringNotContainsString('Empty response.', $display);
    }

    public function testReusedRendererResetsPendingTextAfterAStreamFailure(): void
    {
        $terminal = new VirtualTerminal(rows: 40);
        $view = new ConversationView($terminal, 'Neuron AI', 'Conversation');
        $renderer = new TurnRenderer($view);
        $failure = new RuntimeException('Streaming failed');
        $stream = (static function () use ($failure): Generator {
            yield new TextChunk('same', 'Partial answer.');
            yield new TextChunk('same', '   ');
            throw $failure;
        })();

        try {
            $renderer->run($stream);
            self::fail('The streaming failure must propagate.');
        } catch (RuntimeException $error) {
            self::assertSame($failure, $error);
        }
        self::assertTrue($renderer->run($this->stream([new TextChunk('same', 'Next answer.')])));

        $display = $this->screen($view, $terminal);
        self::assertStringContainsString('● Partial answer.', $display);
        self::assertStringContainsString('● Next answer.', $display);
        self::assertSame(1, substr_count($display, 'RuntimeException: Streaming failed'));
    }

    public function testCompletionFailureIsPresentedAndPropagated(): void
    {
        $terminal = new VirtualTerminal(rows: 40);
        $view = new ConversationView($terminal, 'Neuron AI', 'Conversation');
        $renderer = new TurnRenderer($view);
        $failure = new RuntimeException('Completion failed');
        $state = new class ($failure) extends AgentState {
            public function __construct(private readonly RuntimeException $failure) {}

            public function isInterrupted(): bool
            {
                throw $this->failure;
            }
        };

        try {
            $renderer->run($this->stream([new TextChunk('first', 'Answer.')], $state));
            self::fail('The completion failure must propagate.');
        } catch (RuntimeException $error) {
            self::assertSame($failure, $error);
        }

        self::assertSame(1, substr_count($this->screen($view, $terminal), 'RuntimeException: Completion failed'));
    }

    /**
     * @param list<object> $chunks
     * @return Generator<int, object, mixed, AgentState>
     */
    private function stream(array $chunks, ?AgentState $state = null): Generator
    {
        yield from $chunks;

        return $state ?? new AgentState();
    }

    private function screen(ConversationView $view, VirtualTerminal $terminal): string
    {
        $view->paintPendingChanges();
        $screen = new ScreenBuffer($terminal->getColumns(), $terminal->getRows());
        $screen->write($terminal->getOutput());

        return $screen->getScreen();
    }
}
