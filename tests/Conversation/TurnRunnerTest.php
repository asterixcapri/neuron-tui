<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Conversation;

use Generator;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\Tool;
use NeuronTui\Conversation\TurnInterruption;
use NeuronTui\Conversation\TurnRunner;
use NeuronTui\View\ConversationView;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class TurnRunnerTest extends TestCase
{
    public function testAnInterruptionAtTheFirstAnnouncementDoesNotStartAnyTool(): void
    {
        $interruption = new TurnInterruption();
        $runs = 0;
        $tool = (new Tool('unstarted'))->setCallId('unstarted-id')->setCallable(static function () use (&$runs): string {
            ++$runs;

            return 'Should never run.';
        });
        $call = new ToolCallMessage('Planned operation.', [$tool]);
        $provider = new class($interruption, $call) extends FakeAIProvider {
            public function __construct(private readonly TurnInterruption $interruption, ToolCallMessage $call)
            {
                parent::__construct($call, new AssistantMessage('Next answer.'));
            }

            protected function streamChunks(Message $response): Generator
            {
                yield from parent::streamChunks($response);

                if ($response instanceof ToolCallMessage) {
                    $this->interruption->request();
                }

                return $response;
            }
        };
        $agent = $this->agentOf($provider);
        $runner = new TurnRunner(new ConversationView(new VirtualTerminal(), 'Neuron AI', 'Conversation'));
        EventLoop::queue(static function () use ($runner, $agent, $interruption): void {
            $runner->run($agent, 'First question.', $interruption);
            $runner->run($agent, 'Next question.');
        });
        EventLoop::run();

        self::assertSame(0, $runs);
        $messages = $agent->getChatHistory()->getMessages();
        self::assertCount(5, $messages);
        self::assertSame($call, $messages[1]);
        self::assertInstanceOf(ToolResultMessage::class, $messages[2]);
        self::assertStringContainsString('"status":"not_executed"', $messages[2]->getTools()[0]->getResult());
        $provider->assertCallCount(2);
    }

    public function testInterruptionDuringTheFollowingInferenceRetainsToolResultsAndOnlyItsOwnPartialText(): void
    {
        $interruption = new TurnInterruption();
        $tool = (new Tool('finished'))->setCallId('finished-id')->setCallable(static fn (): string => 'Actual outcome.');
        $call = new ToolCallMessage('Planning prose.', [$tool]);
        $provider = new class($interruption, $call) extends FakeAIProvider {
            public function __construct(private readonly TurnInterruption $interruption, ToolCallMessage $call)
            {
                parent::__construct($call, new AssistantMessage('Partial. Unseen.'), new AssistantMessage('Next answer.'));
            }

            protected function streamChunks(Message $response): Generator
            {
                if ($response->getContent() === 'Partial. Unseen.') {
                    yield new TextChunk('partial', 'Partial.');
                    $this->interruption->request();
                    yield new TextChunk('partial', ' Unseen.');

                    return $response;
                }

                yield from parent::streamChunks($response);

                return $response;
            }
        };
        $agent = $this->agentOf($provider);
        $runner = new TurnRunner(new ConversationView(new VirtualTerminal(), 'Neuron AI', 'Conversation'));
        EventLoop::queue(static function () use ($runner, $agent, $interruption): void {
            $runner->run($agent, 'First question.', $interruption);
            $runner->run($agent, 'Next question.');
        });
        EventLoop::run();

        $messages = $agent->getChatHistory()->getMessages();
        self::assertCount(6, $messages);
        self::assertSame($call, $messages[1]);
        self::assertInstanceOf(ToolResultMessage::class, $messages[2]);
        self::assertSame('Actual outcome.', $messages[2]->getTools()[0]->getResult());
        self::assertSame('Partial.', $messages[3]->getContent());
        self::assertSame('interrupted', $messages[3]->getMetadata('stop_reason'));
        self::assertSame(array_slice($messages, 0, 5), $provider->getRecorded()[2]->messages);
        $provider->assertCallCount(3);
    }

    public function testAnAcceptedInterruptionDuringProviderCompletionReconcilesOnce(): void
    {
        $interruption = new TurnInterruption();
        $provider = new class($interruption) extends FakeAIProvider {
            public function __construct(private readonly TurnInterruption $interruption)
            {
                parent::__construct(new AssistantMessage('Answer.'));
            }

            protected function streamChunks(Message $response): Generator
            {
                yield new TextChunk('race', 'Answer.');
                $this->interruption->request();
                $this->interruption->request();

                return $response;
            }
        };
        $agent = $this->agentOf($provider);
        $view = new ConversationView(new VirtualTerminal(), 'Neuron AI', 'Conversation');
        $runner = new TurnRunner($view);
        EventLoop::queue(static fn () => $runner->run($agent, 'Question.', $interruption));
        EventLoop::run();

        $messages = $agent->getChatHistory()->getMessages();
        self::assertCount(2, $messages);
        self::assertSame('Answer.', $messages[1]->getContent());
        self::assertSame('interrupted', $messages[1]->getMetadata('stop_reason'));
    }

    public function testAnUnansweredInterruptedUserCanBeFollowedByAnotherTurn(): void
    {
        $interruption = new TurnInterruption();
        $interruption->request();
        $agent = $this->agentOf(new FakeAIProvider(new AssistantMessage('Next answer.')));
        $history = $agent->getChatHistory();
        $view = new ConversationView(new VirtualTerminal(), 'Neuron AI', 'Conversation');
        $runner = new TurnRunner($view);
        EventLoop::queue(static function () use ($runner, $agent, $interruption): void {
            $runner->run($agent, 'First question.', $interruption);
            $runner->run($agent, 'Next question.');
        });
        EventLoop::run();

        self::assertSame($history, $agent->getChatHistory());
        self::assertSame(['First question.', 'Next question.', 'Next answer.'], array_map(
            static fn (Message $message): ?string => $message->getContent(),
            $history->getMessages(),
        ));
        self::assertSame('interrupted', $history->getMessages()[0]->getMetadata('stop_reason'));
    }

    public function testTheAnsweredTextIsPaintedIntoTheConversation(): void
    {
        $terminal = new VirtualTerminal(rows: 24);
        $provider = new class(
            new AssistantMessage('Forty-two.'),
        ) extends FakeAIProvider {
            protected function streamChunks(Message $response): Generator
            {
                yield new TextChunk('turn-stream', 'Forty');
                yield new TextChunk('turn-stream', '-two.');

                return $response;
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
        $lookup = (new Tool('lookup'))
            ->setCallId('lookup-call')
            ->setInputs([])
            ->setCallable(static fn (): string => 'Found the record.');
        $check = (new Tool('check'))
            ->setCallId('check-call')
            ->setInputs([])
            ->setCallable(static fn (): string => 'Record verified.');
        $provider = new FakeAIProvider(
            new ToolCallMessage('Finding the record.', [$lookup]),
            new ToolCallMessage('Found it; checking the record.', [$check]),
            new AssistantMessage('The record is verified.'),
        );

        $display = $this->runTurn($provider, 'Find and verify the record.', $terminal);

        self::assertMatchesRegularExpression(
            '/● Finding the record\..*● lookup.*● Found it; checking the record\..*● check.*● The record is verified\./s',
            $display,
        );
        $provider->assertCallCount(3);
    }

    public function testAnAnswerOfWhitespaceAloneIsStillEmpty(): void
    {
        $terminal = new VirtualTerminal(rows: 24);
        $provider = new class(new AssistantMessage()) extends FakeAIProvider {
            protected function streamChunks(Message $response): Generator
            {
                yield new TextChunk('blank-stream', '');
                yield new TextChunk('blank-stream', " \n\t ");

                return $response;
            }
        };

        $display = $this->runTurn($provider, 'Anything?', $terminal);

        self::assertStringContainsString('Empty response.', $display);
    }

    public function testATurnSpentOnToolsAloneIsNotAnEmptyAnswer(): void
    {
        $terminal = new VirtualTerminal(columns: 100, rows: 24);
        $tool = (new Tool('lookup'))
            ->setCallId('lookup-call')
            ->setInputs(['q' => 'alpha'])
            ->setCallable(static fn (): string => 'alpha result');
        $provider = new FakeAIProvider(
            new ToolCallMessage(tools: [$tool]),
            new AssistantMessage(),
        );

        $display = $this->runTurn($provider, 'Run the tool.', $terminal);

        self::assertStringContainsString('● lookup {"q":"alpha"}', $display);
        self::assertStringContainsString('⎿ alpha result', $display);
        self::assertStringNotContainsString('Empty response.', $display);
    }

    public function testAnEmptyTextChunkBeforeAToolDoesNotCreateAnEmptyMessage(): void
    {
        $terminal = new VirtualTerminal(columns: 100, rows: 24);
        $tool = (new Tool('lookup'))
            ->setCallId('lookup-call')
            ->setInputs(['q' => 'alpha'])
            ->setCallable(static fn (): string => 'alpha result');
        $provider = new class(
            new ToolCallMessage(tools: [$tool]),
            new AssistantMessage('Found it.'),
        ) extends FakeAIProvider {
            protected function streamChunks(Message $response): Generator
            {
                if ($response instanceof ToolCallMessage) {
                    yield new TextChunk('empty-before-tool', '');

                    return $response;
                }

                yield from parent::streamChunks($response);

                return $response;
            }
        };

        $display = $this->runTurn($provider, 'Run the tool.', $terminal);

        self::assertDoesNotMatchRegularExpression('/\R ●\h+\R/', $display);
        self::assertStringContainsString('● lookup {"q":"alpha"}', $display);
        self::assertStringContainsString('● Found it.', $display);
    }

    public function testEachTurnIsAnsweredByTheAgentHandedToItThen(): void
    {
        $terminal = new VirtualTerminal(rows: 24);
        $first = new FakeAIProvider(new AssistantMessage('The first one.'));
        $second = new FakeAIProvider(new AssistantMessage('The second one.'));
        $view = new ConversationView($terminal, 'Neuron AI', 'Conversation');
        $turn = new TurnRunner($view);
        $earlier = $this->agentOf($first);
        $later = $this->agentOf($second);

        EventLoop::queue(
            static function () use ($turn, $earlier, $later): void {
                $turn->run($earlier, 'Who answers?');
                $turn->run($later, 'And now?');
            },
        );
        EventLoop::run();

        $view->paintPendingChanges();
        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString('The second one.', $display);
        $first->assertCallCount(1);
        $second->assertCallCount(1);
    }

    private function agentOf(FakeAIProvider $provider): Agent
    {
        $agent = new Agent();
        $agent->setAiProvider($provider);

        return $agent;
    }

    /**
     * Takes one turn against the given provider and reads back what the
     * terminal was told to show.
     */
    private function runTurn(
        FakeAIProvider $provider,
        string $message,
        VirtualTerminal $terminal,
    ): string {
        $agent = $this->agentOf($provider);
        $view = new ConversationView($terminal, 'Neuron AI', 'Conversation');
        $turn = new TurnRunner($view);

        EventLoop::queue(
            static fn () => $turn->run($agent, $message),
        );
        EventLoop::run();

        $view->paintPendingChanges();

        return AnsiUtils::stripAnsiCodes($terminal->getOutput());
    }
}
