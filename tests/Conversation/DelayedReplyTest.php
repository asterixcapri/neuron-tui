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
use NeuronTui\Conversation\ConversationPort;
use NeuronTui\Conversation\ConversationSourceInterface;
use NeuronTui\Conversation\SubagentReply;
use NeuronTui\Tui;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class DelayedReplyTest extends TestCase
{
    public function testAReplyDeliveredWhileIdleStartsANewInvisibleInputTurn(): void
    {
        $tool = new DelayedReplyTool(
            delay: 0.08,
            subagentId: 'child-21',
            reply: 'Private child result.',
        );
        $provider = new FakeAIProvider(
            new ToolCallMessage(tools: [$tool]),
            new AssistantMessage('Delegation started.'),
            new AssistantMessage('I interpreted the child result.'),
        );
        $terminal = new VirtualTerminal(rows: 30);
        $agent = new Agent();
        $agent->setAiProvider($provider);

        EventLoop::queue(
            static fn () => $terminal->simulateInput("Delegate this.\r"),
        );
        EventLoop::delay(
            0.25,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new Tui($agent, $terminal))->run();

        $provider->assertCallCount(3);
        $reply = $this->lastMessageOfCall($provider, 2);
        self::assertSame(
            "Subagent reply\nSubagent ID: child-21\n\nPrivate child result.",
            $reply->getContent(),
        );
        self::assertSame(
            'child-21',
            $reply->getMetadata(SubagentReply::HISTORY_PROVENANCE),
        );
        self::assertSame(1, $tool->executions);

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString('Delegation started.', $display);
        self::assertStringContainsString(
            'I interpreted the child result.',
            $display,
        );
        self::assertStringNotContainsString('Private child result.', $display);
    }

    public function testAReplyWaitsInOrderWithPersonInputsDuringATurn(): void
    {
        $tool = new DelayedReplyTool(
            delay: 0.10,
            subagentId: 'child-34',
            reply: 'Queued child result.',
        );
        $provider = new class(
            new ToolCallMessage(tools: [$tool]),
            new AssistantMessage('Initial answer.'),
            new AssistantMessage('Second answer.'),
            new AssistantMessage('Child interpreted.'),
            new AssistantMessage('Third answer.'),
        ) extends FakeAIProvider {
            private int $response = 0;

            protected function streamChunks(Message $response): Generator
            {
                ++$this->response;

                if ($this->response === 2) {
                    \Amp\delay(0.20);
                }

                yield new TextChunk(
                    'mixed-input-stream',
                    $response->getContent() ?? '',
                );

                return $response;
            }
        };
        $terminal = new VirtualTerminal(rows: 40);
        $agent = new Agent();
        $agent->setAiProvider($provider);

        EventLoop::queue(
            static fn () => $terminal->simulateInput("First question.\r"),
        );
        EventLoop::delay(
            0.05,
            static fn () => $terminal->simulateInput("Second question.\r"),
        );
        EventLoop::delay(
            0.15,
            static fn () => $terminal->simulateInput("Third question.\r"),
        );
        EventLoop::delay(
            0.55,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new Tui($agent, $terminal))->run();

        $provider->assertCallCount(5);
        self::assertSame(
            'Second question.',
            $this->lastMessageOfCall($provider, 2)->getContent(),
        );
        self::assertSame(
            "Subagent reply\nSubagent ID: child-34\n\nQueued child result.",
            $this->lastMessageOfCall($provider, 3)->getContent(),
        );
        self::assertSame(
            'Third question.',
            $this->lastMessageOfCall($provider, 4)->getContent(),
        );

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringNotContainsString('Queued child result.', $display);
        self::assertStringContainsString('Second question.', $display);
        self::assertStringContainsString('Third question.', $display);
    }

    private function lastMessageOfCall(
        FakeAIProvider $provider,
        int $call,
    ): Message {
        $messages = $provider->getRecorded()[$call]->messages;
        $message = end($messages);

        if (!$message instanceof Message) {
            throw new \LogicException('The provider call had no messages.');
        }

        return $message;
    }
}

final class DelayedReplyTool extends Tool implements ConversationSourceInterface
{
    private ?ConversationPort $conversation = null;

    public int $executions = 0;

    public function __construct(
        private readonly float $delay,
        private readonly string $subagentId,
        private readonly string $reply,
    ) {
        parent::__construct('start_subagent');
        $this->setCallable($this->start(...));
    }

    public function connect(ConversationPort $conversation): void
    {
        $this->conversation = $conversation;
    }

    private function start(): string
    {
        ++$this->executions;
        $conversation = $this->conversation;

        if (!$conversation instanceof ConversationPort) {
            throw new \LogicException('Tool was not connected.');
        }

        EventLoop::delay(
            $this->delay,
            function () use ($conversation): void {
                $conversation->deliver(
                    new SubagentReply($this->subagentId, $this->reply),
                );
            },
        );

        return "Started {$this->subagentId}.";
    }
}
