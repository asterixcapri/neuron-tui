<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Subagent;

use InvalidArgumentException;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;
use NeuronTui\Conversation\ConversationPort;
use NeuronTui\Conversation\ConversationSourceInterface;
use NeuronTui\Conversation\SubagentReply;
use NeuronTui\Subagent\ChildTurn;
use NeuronTui\Subagent\ChildTurnTask;
use NeuronTui\Subagent\SubagentToolkit;
use NeuronTui\Tests\Subagent\Fixture\WorkerAgent;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

final class SubagentToolkitTest extends TestCase
{
    public function testTheToolkitExposesTheThreeModelTools(): void
    {
        $tools = (new SubagentToolkit(WorkerAgent::class))->provide();

        self::assertSame(
            ['subagent', 'subagent_send', 'subagent_status'],
            array_map(
                static fn (ToolInterface $tool): string => $tool->getName(),
                $tools,
            ),
        );
    }

    public function testTheToolkitRejectsAnInvalidAgentOrConcurrency(): void
    {
        try {
            new SubagentToolkit(self::class);
            self::fail('An unrelated class was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('must extend', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('positive integer');
        new SubagentToolkit(Agent::class, 0);
    }

    public function testAChildTaskContainsOnlySerializableHistoryData(): void
    {
        $closureTool = (new Tool('closure_tool'))
            ->setCallable(static fn (): string => 'not serialized');
        $history = new InMemoryChatHistory();
        $history->addMessage(new ToolCallMessage(tools: [$closureTool]));
        $json = json_encode($history->jsonSerialize(), JSON_THROW_ON_ERROR);

        /** @var list<array<string, mixed>> $messages */
        $messages = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $task = new ChildTurnTask(new ChildTurn(
            WorkerAgent::class,
            'Continue.',
            $messages,
        ));

        self::assertStringNotContainsString('not serialized', serialize($task));
    }

    public function testStartingReturnsImmediatelyAndDeliversTheWorkerReplyLater(): void
    {
        $replies = new ReplyCollector();
        $tool = (new SubagentToolkit(WorkerAgent::class))->provide()[0];
        self::assertInstanceOf(ConversationSourceInterface::class, $tool);
        $tool->connect(new ConversationPort(
            static function (SubagentReply $reply) use (&$replies): void {
                $replies->reply = $reply;
                EventLoop::getDriver()->stop();
            },
        ));
        $tool->setInputs(['task' => 'Do this in another process.']);

        ob_start();
        $tool->execute();
        $immediateOutput = ob_get_clean();
        self::assertSame('', $immediateOutput);

        /** @var array{id: string, state: string} $started */
        $started = json_decode($tool->getResult(), true, flags: JSON_THROW_ON_ERROR);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $started['id']);
        self::assertSame('running', $started['state']);
        self::assertNull($replies->reply);

        $timedOut = false;
        $timeout = EventLoop::delay(5, static function () use (&$timedOut): void {
            $timedOut = true;
            EventLoop::getDriver()->stop();
        });
        EventLoop::run();

        if (!$timedOut) {
            EventLoop::cancel($timeout);
        }

        self::assertFalse($timedOut, 'The worker did not complete in time.');
        $reply = $replies->reply;
        self::assertInstanceOf(SubagentReply::class, $reply);
        self::assertSame($started['id'], $reply->subagentId);
        self::assertSame('Background result.', $reply->contents);
    }
}

final class ReplyCollector
{
    public ?SubagentReply $reply = null;
}
