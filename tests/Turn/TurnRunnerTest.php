<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Turn;

use Generator;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Testing\FakeAIProvider;
use NeuronInteraction\Command\CommandInput;
use NeuronInteraction\Conversation;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronTui\Tests\Tools\CallbackTool;
use NeuronTui\Turn\TurnRunner;
use NeuronTui\View\ConversationView;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use RuntimeException;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\ScreenBuffer;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

use function substr_count;

final class TurnRunnerTest extends TestCase
{
    public function testTheAnsweredTextIsPaintedIntoTheConversation(): void
    {
        $terminal = new VirtualTerminal(rows: 24);
        $provider = new class (
            new AssistantMessage('Forty-two.'),
        ) extends FakeAIProvider {
            protected function streamChunks(Message $response): Generator
            {
                yield new TextChunk('turn-stream', 'Forty');
                yield new TextChunk('turn-stream', '-two.');

                return new ProviderResponse(message: $response);
            }
        };

        $display = $this->runTurn(
            $provider,
            'What is the answer?',
            $terminal,
        );

        self::assertStringContainsString('● Forty-two.', $display);
        self::assertStringNotContainsString('Empty response.', $display);
    }

    public function testAnAnswerWithNothingInItIsCalledEmpty(): void
    {
        $terminal = new VirtualTerminal(rows: 24);

        $display = $this->runTurn(
            new FakeAIProvider(new AssistantMessage()),
            'Anything?',
            $terminal,
        );

        self::assertStringContainsString('Empty response.', $display);
    }

    public function testProgressAfterToolsAppearsAsANewMessageInStreamOrder(): void
    {
        $terminal = new VirtualTerminal(columns: 120, rows: 40);
        $lookup = (new CallbackTool('lookup'))
            ->setCallId('lookup-call')
            ->setInputs([])
            ->setCallable(static fn(): string => 'Found the record.');
        $check = (new CallbackTool('check'))
            ->setCallId('check-call')
            ->setInputs([])
            ->setCallable(static fn(): string => 'Record verified.');
        $provider = new FakeAIProvider(
            new ToolCallMessage('Finding the record.', [$lookup->call()]),
            new ToolCallMessage('Found it; checking the record.', [$check->call()]),
            new AssistantMessage('The record is verified.'),
        );

        $display = $this->runTurn($provider, 'Find and verify the record.', $terminal, [$lookup, $check]);

        self::assertMatchesRegularExpression(
            '/● Finding the record\..*● lookup.*● Found it; checking the record\..*● check.*● The record is verified\./s',
            $display,
        );
        $provider->assertCallCount(3);
    }

    public function testAnAnswerOfWhitespaceAloneIsStillEmpty(): void
    {
        $terminal = new VirtualTerminal(rows: 24);
        $provider = new class (new AssistantMessage()) extends FakeAIProvider {
            protected function streamChunks(Message $response): Generator
            {
                yield new TextChunk('blank-stream', '');
                yield new TextChunk('blank-stream', " \n\t ");

                return new ProviderResponse(message: $response);
            }
        };

        $display = $this->runTurn($provider, 'Anything?', $terminal);

        self::assertStringContainsString('Empty response.', $display);
    }

    public function testATurnSpentOnToolsAloneIsNotAnEmptyAnswer(): void
    {
        $terminal = new VirtualTerminal(columns: 100, rows: 24);
        $tool = (new CallbackTool('lookup'))
            ->setCallId('lookup-call')
            ->setInputs(['q' => 'alpha'])
            ->setCallable(static fn(): string => 'alpha result');
        $provider = new FakeAIProvider(
            new ToolCallMessage(tools: [$tool->call()]),
            new AssistantMessage(),
        );

        $display = $this->runTurn($provider, 'Run the tool.', $terminal, [$tool]);

        self::assertStringContainsString('● lookup {"q":"alpha"}', $display);
        self::assertStringContainsString('⎿ alpha result', $display);
        self::assertStringNotContainsString('Empty response.', $display);
    }

    public function testAnEmptyTextChunkBeforeAToolDoesNotCreateAnEmptyMessage(): void
    {
        $terminal = new VirtualTerminal(columns: 100, rows: 24);
        $tool = (new CallbackTool('lookup'))
            ->setCallId('lookup-call')
            ->setInputs(['q' => 'alpha'])
            ->setCallable(static fn(): string => 'alpha result');
        $provider = new class (
            new ToolCallMessage(tools: [$tool->call()]),
            new AssistantMessage('Found it.'),
        ) extends FakeAIProvider {
            protected function streamChunks(Message $response): Generator
            {
                if ($response instanceof ToolCallMessage) {
                    yield new TextChunk('empty-before-tool', '');

                    return new ProviderResponse(message: $response);
                }

                yield from parent::streamChunks($response);

                return new ProviderResponse(message: $response);
            }
        };

        $display = $this->runTurn($provider, 'Run the tool.', $terminal, [$tool]);

        self::assertDoesNotMatchRegularExpression('/\R ●\h+\R/', $display);
        self::assertStringContainsString('● lookup {"q":"alpha"}', $display);
        self::assertStringContainsString('● Found it.', $display);
    }

    public function testEachTurnIsAnsweredByTheCurrentConversationAgent(): void
    {
        $terminal = new VirtualTerminal(rows: 24);
        $first = new FakeAIProvider(new AssistantMessage('The first one.'));
        $second = new FakeAIProvider(new AssistantMessage('The second one.'));
        $view = new ConversationView($terminal, 'Neuron AI', 'Conversation');
        $earlier = $this->agentOf($first);
        $later = $this->agentOf($second);
        $conversation = new Conversation($earlier, (new SessionStore(new InMemoryStorage(), 'test'))->create());
        $turn = new TurnRunner($conversation, $view, static function (CommandInput $input): void {});

        EventLoop::queue(
            static function () use ($turn, $conversation, $later): void {
                $turn->run($turn->sendInput(new UserMessage('Who answers?')));
                $conversation->useAgent($later);
                $turn->run($turn->sendInput(new UserMessage('And now?')));
            },
        );
        EventLoop::run();

        $view->paintPendingChanges();
        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString('The second one.', $display);
        $first->assertCallCount(1);
        $second->assertCallCount(1);
    }

    public function testAnApprovalPauseDoesNotCompleteTheTurnOrShowAnEmptyResponse(): void
    {
        $terminal = new VirtualTerminal(rows: 24);
        $tool = (new CallbackTool('publish'))->setCallId('publish-call');
        $tool->requireApproval();
        $provider = new FakeAIProvider(new ToolCallMessage(tools: [$tool->call()]));
        $agent = $this->agentOf($provider)->addTool($tool);
        $view = new ConversationView($terminal, 'Neuron AI', 'Conversation');
        $turn = $this->runner($view, $agent);
        $completed = null;
        EventLoop::queue(static function () use ($turn, &$completed): void {
            $completed = $turn->run($turn->sendInput(new UserMessage('Publish now')));
        });
        EventLoop::run();
        $view->paintPendingChanges();
        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertFalse($completed);
        self::assertStringContainsString('Human-in-the-loop interruptions are not supported.', $display);
        self::assertStringNotContainsString('Empty response.', $display);
        $provider->assertCallCount(1);
    }

    public function testAStreamFailureRendersTheErrorOnceAfterPartialText(): void
    {
        $terminal = new VirtualTerminal(rows: 24);
        $provider = new class (new AssistantMessage()) extends FakeAIProvider {
            protected function streamChunks(Message $response): Generator
            {
                yield new TextChunk('failed-stream', 'Partial answer.');
                throw new RuntimeException('Provider failed');
            }
        };
        $view = new ConversationView($terminal, 'Neuron AI', 'Conversation');
        $agent = $this->agentOf($provider);
        $turn = $this->runner($view, $agent);
        EventLoop::queue(static function () use ($turn): void {
            try {
                $turn->run($turn->sendInput(new UserMessage('Answer')));
            } catch (RuntimeException) {
                // The client can advance its queue after rendering the native stream error.
            }
        });
        EventLoop::run();
        $view->paintPendingChanges();
        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString('Partial answer.', $display);
        self::assertStringContainsString('RuntimeException: Provider failed', $display);
        self::assertStringNotContainsString('Empty response.', $display);
    }

    public function testConsumedStopBeforeTextStillPresentsStoppedAndPropagatesFailure(): void
    {
        $terminal = new VirtualTerminal(rows: 24);
        $provider = new class (new AssistantMessage()) extends FakeAIProvider {
            protected function streamChunks(Message $response): Generator
            {
                yield from [];
                throw new RuntimeException('Stopped before text');
            }
        };
        $view = new ConversationView($terminal, 'Neuron AI', 'Conversation');
        $agent = $this->agentOf($provider);
        $turn = $this->runner($view, $agent);
        $failure = null;
        EventLoop::queue(static function () use ($turn, &$failure): void {
            try {
                $turn->run($turn->sendInput(new UserMessage('Answer')), static fn(): bool => true);
            } catch (RuntimeException $error) {
                $failure = $error;
            }
        });
        EventLoop::run();
        $view->paintPendingChanges();
        self::assertInstanceOf(RuntimeException::class, $failure);
        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString('Stopped', $display);
        self::assertStringContainsString('RuntimeException: Stopped before text', $display);
        self::assertStringNotContainsString('Empty response.', $display);
    }

    public function testReusedRunnerDoesNotCarryTextOrToolActivityIntoAnEmptyTurn(): void
    {
        $terminal = new VirtualTerminal(rows: 40);
        $view = new ConversationView($terminal, 'Neuron AI', 'Conversation');
        $runner = $this->runner($view);
        $tool = (new CallbackTool('lookup'))->setCallId('lookup-call');

        self::assertTrue($runner->run($this->stream([
            new TextChunk('first', 'First answer.'),
            new ToolCallChunk('first', $tool->call()),
        ])));
        self::assertTrue($runner->run($this->stream([])));
        self::assertTrue($runner->run($this->stream([new TextChunk('blank', " \n\t")])));

        $display = $this->screen($view, $terminal);
        self::assertStringContainsString('First answer.', $display);
        self::assertStringContainsString('lookup', $display);
        self::assertSame(2, substr_count($display, 'Empty response.'));
    }

    public function testWhitespaceBetweenChunksIsPreservedAndMessageChangesCreateSeparateEntries(): void
    {
        $terminal = new VirtualTerminal(rows: 40);
        $view = new ConversationView($terminal, 'Neuron AI', 'Conversation');
        $runner = $this->runner($view);

        self::assertTrue($runner->run($this->stream([
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

    public function testReusedRunnerResetsPendingTextAfterAStreamFailure(): void
    {
        $terminal = new VirtualTerminal(rows: 40);
        $view = new ConversationView($terminal, 'Neuron AI', 'Conversation');
        $runner = $this->runner($view);
        $failure = new RuntimeException('Streaming failed');
        $stream = (static function () use ($failure): Generator {
            yield new TextChunk('same', 'Partial answer.');
            yield new TextChunk('same', '   ');
            throw $failure;
        })();

        try {
            $runner->run($stream);
            self::fail('The streaming failure must propagate.');
        } catch (RuntimeException $error) {
            self::assertSame($failure, $error);
        }
        self::assertTrue($runner->run($this->stream([new TextChunk('same', 'Next answer.')])));

        $display = $this->screen($view, $terminal);
        self::assertStringContainsString('● Partial answer.', $display);
        self::assertStringContainsString('● Next answer.', $display);
        self::assertSame(1, substr_count($display, 'RuntimeException: Streaming failed'));
    }

    public function testCompletionFailureIsPresentedAndPropagated(): void
    {
        $terminal = new VirtualTerminal(rows: 40);
        $view = new ConversationView($terminal, 'Neuron AI', 'Conversation');
        $runner = $this->runner($view);
        $failure = new RuntimeException('Completion failed');
        $state = new class ($failure) extends AgentState {
            public function __construct(private readonly RuntimeException $failure) {}

            public function isInterrupted(): bool
            {
                throw $this->failure;
            }
        };

        try {
            $runner->run($this->stream([new TextChunk('first', 'Answer.')], $state));
            self::fail('The completion failure must propagate.');
        } catch (RuntimeException $error) {
            self::assertSame($failure, $error);
        }

        self::assertSame(1, substr_count($this->screen($view, $terminal), 'RuntimeException: Completion failed'));
    }

    private function runner(ConversationView $view, ?Agent $agent = null): TurnRunner
    {
        return new TurnRunner(
            new Conversation($agent ?? new Agent(), (new SessionStore(new InMemoryStorage(), 'test'))->create()),
            $view,
            static function (CommandInput $input): void {},
        );
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

    private function agentOf(FakeAIProvider $provider): Agent
    {
        $agent = (new Agent())->setThreadId('test-thread');
        $agent->setAiProvider($provider);

        return $agent;
    }

    /**
     * Takes one turn against the given provider and reads back what the
     * terminal was told to show.
     *
     * @param list<CallbackTool> $tools
     */
    private function runTurn(
        FakeAIProvider $provider,
        string $message,
        VirtualTerminal $terminal,
        array $tools = [],
    ): string {
        $agent = $this->agentOf($provider);
        $agent->addTool($tools);
        $view = new ConversationView($terminal, 'Neuron AI', 'Conversation');
        $turn = $this->runner($view, $agent);

        EventLoop::queue(
            static fn() => $turn->run($turn->sendInput(new UserMessage($message))),
        );
        EventLoop::run();

        $view->paintPendingChanges();

        return AnsiUtils::stripAnsiCodes($terminal->getOutput());
    }
}
