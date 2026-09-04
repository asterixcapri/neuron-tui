<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Conversation;

use Generator;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\Tool;
use NeuronTui\Conversation\AgentTurn;
use NeuronTui\Conversation\ConversationPort;
use NeuronTui\Conversation\ConversationSource;
use NeuronTui\Conversation\MessageForAgent;
use NeuronTui\Conversation\SubagentReply;
use NeuronTui\Tui\ConversationView;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class AgentTurnTest extends TestCase
{
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

        $display = $this->respond(
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

        $display = $this->respond(
            new FakeAIProvider(new AssistantMessage()),
            'Anything?',
            $terminal,
        );

        self::assertStringContainsString('Empty response.', $display);
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

        $display = $this->respond($provider, 'Anything?', $terminal);

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

        $display = $this->respond($provider, 'Run the tool.', $terminal);

        self::assertStringContainsString('● lookup {"q":"alpha"}', $display);
        self::assertStringContainsString('⎿ alpha result', $display);
        self::assertStringNotContainsString('Empty response.', $display);
    }

    public function testEachTurnIsAnsweredByTheAgentHandedToItThen(): void
    {
        $terminal = new VirtualTerminal(rows: 24);
        $first = new FakeAIProvider(new AssistantMessage('The first one.'));
        $second = new FakeAIProvider(new AssistantMessage('The second one.'));
        $view = new ConversationView($terminal, 'Neuron AI', 'Conversation');
        $turn = $this->turn($view);
        $earlier = $this->agentOf($first);
        $later = $this->agentOf($second);

        EventLoop::queue(
            static function () use ($turn, $earlier, $later): void {
                $turn->respond($earlier, new MessageForAgent('Who answers?'));
                $turn->respond($later, new MessageForAgent('And now?'));
            },
        );
        EventLoop::run();

        $view->paintPendingChanges();
        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString('The second one.', $display);
        $first->assertCallCount(1);
        $second->assertCallCount(1);
    }

    public function testAConversationSourceIsConnectedBeforeItExecutes(): void
    {
        $delivered = [];
        $terminal = new VirtualTerminal(rows: 24);
        $view = new ConversationView($terminal, 'Neuron AI', 'Conversation');
        $conversation = new ConversationPort(
            static function (SubagentReply $reply) use (&$delivered): void {
                $delivered[] = $reply;
            },
        );
        $tool = new class() extends Tool implements ConversationSource {
            private ?ConversationPort $conversation = null;

            public function __construct()
            {
                parent::__construct('start_later');
                $this->setCallable(function (): string {
                    if (!$this->conversation instanceof ConversationPort) {
                        throw new \LogicException('Tool was not connected.');
                    }

                    $this->conversation->deliver(
                        new SubagentReply('child-12', 'Finished later.'),
                    );

                    return 'Started child-12.';
                });
            }

            public function connect(ConversationPort $conversation): void
            {
                $this->conversation = $conversation;
            }
        };
        $provider = new FakeAIProvider(
            new ToolCallMessage(tools: [$tool]),
            new AssistantMessage('Work started.'),
        );
        $turn = new AgentTurn($view, $conversation);
        $agent = $this->agentOf($provider);

        EventLoop::queue(
            static fn () => $turn->respond(
                $agent,
                new MessageForAgent('Delegate this.'),
            ),
        );
        EventLoop::run();

        self::assertCount(1, $delivered);
        self::assertSame('child-12', $delivered[0]->subagentId);
        self::assertSame('Finished later.', $delivered[0]->contents);
        self::assertSame('Started child-12.', $tool->getResult());
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
    private function respond(
        FakeAIProvider $provider,
        string $message,
        VirtualTerminal $terminal,
    ): string {
        $agent = $this->agentOf($provider);
        $view = new ConversationView($terminal, 'Neuron AI', 'Conversation');
        $turn = $this->turn($view);

        EventLoop::queue(
            static fn () => $turn->respond(
                $agent,
                new MessageForAgent($message),
            ),
        );
        EventLoop::run();

        $view->paintPendingChanges();

        return AnsiUtils::stripAnsiCodes($terminal->getOutput());
    }

    private function turn(ConversationView $view): AgentTurn
    {
        return new AgentTurn(
            $view,
            new ConversationPort(static function (SubagentReply $reply): void {
            }),
        );
    }
}
