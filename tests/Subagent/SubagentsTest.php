<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Subagent;

use Amp\DeferredFuture;
use Amp\Future;
use LogicException;
use NeuronAI\Agent\Agent;
use NeuronTui\Conversation\ConversationPort;
use NeuronTui\Conversation\SubagentReply;
use NeuronTui\Subagent\ChildTurnExecutorInterface;
use NeuronTui\Subagent\ChildTurnResult;
use NeuronTui\Subagent\Subagents;
use NeuronTui\Tests\Subagent\Fixture\WorkerAgent;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use RuntimeException;

final class SubagentsTest extends TestCase
{
    public function testMessagesWaitInFifoAndEachTurnReceivesCompletedHistory(): void
    {
        $executor = new ControllableChildTurnExecutor();
        $replies = [];
        $subagents = $this->connectedSubagents($executor, $replies);

        $started = $subagents->start('first');
        $id = $started['id'];
        EventLoop::run();
        $subagents->send($id, 'second');
        $subagents->send($id, 'third');

        self::assertSame('running', $subagents->status($id)['state']);
        self::assertSame(2, $subagents->status($id)['queued_messages']);
        self::assertSame([], $subagents->status($id)['history']);
        self::assertArrayHasKey('elapsed_seconds', $subagents->status($id));
        self::assertSame([['message' => 'first', 'history' => []]], $executor->calls);

        $firstHistory = [['role' => 'assistant', 'content' => 'one']];
        $executor->complete(0, new ChildTurnResult('reply one', $firstHistory));
        EventLoop::run();

        self::assertSame(
            [
                ['message' => 'first', 'history' => []],
                ['message' => 'second', 'history' => $firstHistory],
            ],
            $executor->calls,
        );
        self::assertSame(1, $subagents->status($id)['queued_messages']);
        self::assertSame($firstHistory, $subagents->status($id)['history']);

        $secondHistory = [['role' => 'assistant', 'content' => 'two']];
        $executor->complete(1, new ChildTurnResult('reply two', $secondHistory));
        EventLoop::run();

        self::assertSame('third', $executor->calls[2]['message']);
        self::assertSame($secondHistory, $executor->calls[2]['history']);

        $thirdHistory = [['role' => 'assistant', 'content' => 'three']];
        $executor->complete(2, new ChildTurnResult('reply three', $thirdHistory));
        EventLoop::run();

        $status = $subagents->status($id);
        self::assertSame('idle', $status['state']);
        self::assertSame(0, $status['queued_messages']);
        self::assertSame($thirdHistory, $status['history']);
        self::assertArrayNotHasKey('elapsed_seconds', $status);
        self::assertSame(['reply one', 'reply two', 'reply three'], array_map(
            static fn (SubagentReply $reply): string => $reply->contents,
            $replies,
        ));
    }

    public function testAnIdleSubagentCanBeContinuedWithoutCallingAProviderForStatus(): void
    {
        $executor = new ControllableChildTurnExecutor();
        $replies = [];
        $subagents = $this->connectedSubagents($executor, $replies);
        $id = $subagents->start('first')['id'];
        EventLoop::run();
        $history = [['role' => 'assistant', 'content' => 'complete']];

        $executor->complete(0, new ChildTurnResult('done', $history));
        EventLoop::run();

        self::assertSame($subagents->status($id), $subagents->status($id));
        self::assertCount(1, $executor->calls);

        self::assertSame('running', $subagents->send($id, 'follow up')['state']);
        EventLoop::run();
        self::assertSame(
            ['message' => 'follow up', 'history' => $history],
            $executor->calls[1],
        );
    }

    public function testUnknownAndFailedSubagentsRejectMessagesClearly(): void
    {
        $executor = new ControllableChildTurnExecutor();
        $replies = [];
        $subagents = $this->connectedSubagents($executor, $replies);

        try {
            $subagents->status('missing');
            self::fail('An unknown ID was accepted.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('Unknown subagent ID', $exception->getMessage());
        }

        $id = $subagents->start('first')['id'];
        EventLoop::run();
        $subagents->send($id, 'discard me');
        $executor->fail(0);
        EventLoop::run();

        $status = $subagents->status($id);
        self::assertSame('failed', $status['state']);
        self::assertSame(0, $status['queued_messages']);
        self::assertSame([], $status['history']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('create a new subagent to retry');
        $subagents->send($id, 'retry');
    }

    /**
     * @param list<SubagentReply> $replies
     */
    private function connectedSubagents(
        ControllableChildTurnExecutor $executor,
        array &$replies,
    ): Subagents {
        $subagents = new Subagents(WorkerAgent::class, $executor);
        $subagents->connect(new ConversationPort(
            static function (SubagentReply $reply) use (&$replies): void {
                $replies[] = $reply;
            },
        ));

        return $subagents;
    }
}

final class ControllableChildTurnExecutor implements ChildTurnExecutorInterface
{
    /** @var list<array{message: string, history: list<array<string, mixed>>}> */
    public array $calls = [];

    /** @var list<PendingChildTurn> */
    private array $turns = [];

    public function execute(
        string $agentClass,
        string $message,
        array $history,
    ): Future {
        $this->calls[] = ['message' => $message, 'history' => $history];
        $turn = new PendingChildTurn();
        $this->turns[] = $turn;

        return $turn->future();
    }

    public function complete(int $turn, ChildTurnResult $result): void
    {
        $this->turns[$turn]->complete($result);
    }

    public function fail(int $turn): void
    {
        $this->turns[$turn]->fail(new RuntimeException('provider secret'));
    }
}

final class PendingChildTurn
{
    /** @var DeferredFuture<ChildTurnResult> */
    private readonly DeferredFuture $future;

    public function __construct()
    {
        $this->future = new DeferredFuture();
    }

    /** @return Future<ChildTurnResult> */
    public function future(): Future
    {
        return $this->future->getFuture();
    }

    public function complete(ChildTurnResult $result): void
    {
        $this->future->complete($result);
    }

    public function fail(RuntimeException $exception): void
    {
        $this->future->error($exception);
    }
}
