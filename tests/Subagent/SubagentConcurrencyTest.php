<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Subagent;

use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\Future;
use NeuronAI\Agent\Agent;
use NeuronTui\Conversation\ConversationPort;
use NeuronTui\Conversation\SubagentReply;
use NeuronTui\Subagent\ChildTurnExecutorInterface;
use NeuronTui\Subagent\ChildTurnResult;
use NeuronTui\Subagent\Subagents;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use RuntimeException;

final class SubagentConcurrencyTest extends TestCase
{
    public function testTurnsOverlapUpToTheConfiguredLimitAndTheQueueAdvances(): void
    {
        $executor = new ConcurrencyChildTurnExecutor();
        $replies = new ReplyList();
        $subagents = new Subagents(Agent::class, 2, $executor);
        $subagents->connect($this->port($replies));

        $first = $subagents->start('first');
        $second = $subagents->start('second');
        $third = $subagents->start('third');

        self::assertSame('running', $first['state']);
        self::assertSame('running', $second['state']);
        self::assertSame('queued', $third['state']);

        $executor->runUntilStarted(2);

        self::assertSame(['first', 'second'], $executor->startedMessages());
        self::assertSame(2, $executor->pendingCount());

        $executor->complete(0, 'first result');
        $executor->runUntilStarted(3);

        self::assertSame(['first', 'second', 'third'], $executor->startedMessages());
        self::assertSame('idle', $subagents->status($first['id'])['state']);
        self::assertSame('running', $subagents->status($third['id'])['state']);

        $executor->complete(1, 'second result');
        $executor->complete(2, 'third result');
        $replies->runUntilCount(3);
    }

    public function testTheDefaultLimitIsFourActiveChildTurns(): void
    {
        $executor = new ConcurrencyChildTurnExecutor();
        $replies = new ReplyList();
        $subagents = new Subagents(Agent::class, executor: $executor);
        $subagents->connect($this->port($replies));

        $started = [];

        foreach (range(1, 5) as $number) {
            $started[] = $subagents->start("task {$number}");
        }

        self::assertSame(
            ['running', 'running', 'running', 'running', 'queued'],
            array_column($started, 'state'),
        );

        $executor->runUntilStarted(4);
        self::assertSame(4, $executor->pendingCount());

        $executor->complete(0, 'result 1');
        $executor->runUntilStarted(5);
        self::assertSame('running', $subagents->status($started[4]['id'])['state']);

        foreach (range(1, 4) as $index) {
            $executor->complete($index, 'result '.($index + 1));
        }

        $replies->runUntilCount(5);
    }

    public function testFailureReleasesCapacityForTheNextQueuedTurn(): void
    {
        $executor = new ConcurrencyChildTurnExecutor();
        $replies = new ReplyList();
        $subagents = new Subagents(Agent::class, 1, $executor);
        $subagents->connect($this->port($replies));

        $first = $subagents->start('will fail');
        $second = $subagents->start('runs afterwards');
        $executor->runUntilStarted(1);

        $executor->fail(0);
        $executor->runUntilStarted(2);

        self::assertSame('failed', $subagents->status($first['id'])['state']);
        self::assertSame('running', $subagents->status($second['id'])['state']);
        self::assertSame(
            ['will fail', 'runs afterwards'],
            $executor->startedMessages(),
        );

        $executor->complete(1, 'second result');
        $replies->runUntilCount(2);
    }

    public function testSessionClosureCancelsConcurrentTurnsAndTheirQueue(): void
    {
        $executor = new ConcurrencyChildTurnExecutor();
        $replies = new ReplyList();
        $subagents = new Subagents(Agent::class, 2, $executor);
        $port = $this->port($replies);
        $subagents->connect($port);
        $first = $subagents->start('first');
        $second = $subagents->start('second');
        $third = $subagents->start('queued');
        $executor->runUntilStarted(2);

        $port->close();
        EventLoop::run();

        self::assertSame(1, $executor->cancellations);
        self::assertTrue($executor->turnCancellations[0]->isRequested());
        self::assertTrue($executor->turnCancellations[1]->isRequested());

        foreach ([$first, $second, $third] as $forgotten) {
            try {
                $subagents->status($forgotten['id']);
                self::fail('A cancelled Subagent remained registered.');
            } catch (\LogicException $exception) {
                self::assertStringContainsString(
                    'Unknown subagent ID',
                    $exception->getMessage(),
                );
            }
        }

        $executor->complete(0, 'late first');
        $executor->complete(1, 'late second');
        EventLoop::run();
        self::assertSame(0, $replies->count());
        self::assertSame(['first', 'second'], $executor->startedMessages());
    }

    private function port(ReplyList $replies): ConversationPort
    {
        return new ConversationPort(
            static function (SubagentReply $reply) use ($replies): void {
                $replies->add($reply);
            },
        );
    }
}

final class ConcurrencyChildTurnExecutor implements ChildTurnExecutorInterface
{
    /** @var list<string> */
    private array $messages = [];

    /** @var list<DeferredFuture<mixed>> */
    private array $turns = [];

    private ?int $stopAfterStarts = null;

    /** @var list<Cancellation> */
    public array $turnCancellations = [];

    public int $cancellations = 0;

    public function execute(
        string $agentClass,
        string $message,
        array $history,
        Cancellation $cancellation,
    ): Future {
        $turn = new DeferredFuture();
        $this->messages[] = $message;
        $this->turns[] = $turn;
        $this->turnCancellations[] = $cancellation;

        if (
            $this->stopAfterStarts !== null
            && count($this->turns) >= $this->stopAfterStarts
        ) {
            EventLoop::getDriver()->stop();
        }

        return $turn->getFuture()->map(
            static function (mixed $result): ChildTurnResult {
                if (!$result instanceof ChildTurnResult) {
                    throw new RuntimeException('Unexpected child Turn result.');
                }

                return $result;
            },
        );
    }

    public function cancel(): void
    {
        ++$this->cancellations;
    }

    /** @return list<string> */
    public function startedMessages(): array
    {
        return $this->messages;
    }

    public function pendingCount(): int
    {
        return count(array_filter(
            $this->turns,
            static fn (DeferredFuture $turn): bool => !$turn->isComplete(),
        ));
    }

    public function complete(int $index, string $reply): void
    {
        $this->turns[$index]->complete(new ChildTurnResult($reply, []));
    }

    public function fail(int $index): void
    {
        $this->turns[$index]->error(new RuntimeException('Expected failure.'));
    }

    public function runUntilStarted(int $count): void
    {
        if (count($this->turns) >= $count) {
            return;
        }

        $this->stopAfterStarts = $count;
        EventLoop::run();
        $this->stopAfterStarts = null;

        TestCase::assertCount($count, $this->turns);
    }
}

final class ReplyList
{
    /** @var list<SubagentReply> */
    private array $replies = [];

    private ?int $stopAfterReplies = null;

    public function add(SubagentReply $reply): void
    {
        $this->replies[] = $reply;

        if (
            $this->stopAfterReplies !== null
            && count($this->replies) >= $this->stopAfterReplies
        ) {
            EventLoop::getDriver()->stop();
        }
    }

    public function runUntilCount(int $count): void
    {
        if (count($this->replies) >= $count) {
            return;
        }

        $this->stopAfterReplies = $count;
        EventLoop::run();
        $this->stopAfterReplies = null;

        TestCase::assertCount($count, $this->replies);
    }

    public function count(): int
    {
        return count($this->replies);
    }
}
