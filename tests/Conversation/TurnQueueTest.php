<?php

declare(strict_types=1);

namespace NeuronCli\Tests\Conversation;

use NeuronCli\Conversation\TurnQueue;
use PHPUnit\Framework\TestCase;

final class TurnQueueTest extends TestCase
{
    public function testTheFirstMessageStartsATurnStraightAway(): void
    {
        $turns = new TurnQueue();

        self::assertSame('First question', $turns->accept('First question'));
        self::assertSame([], $turns->queued());
        self::assertSame('First question', $turns->beginWorking());
    }

    public function testAMessageAcceptedButNotYetSentAlreadyOccupiesTheTurn(): void
    {
        $turns = new TurnQueue();
        $turns->accept('First question');

        self::assertNull($turns->accept('Second question'));
        self::assertSame(['Second question'], $turns->queued());
    }

    public function testAMessageArrivingWhileTheAgentWorksWaitsBehindTheTurn(): void
    {
        $turns = new TurnQueue();
        $turns->accept('First question');
        $turns->beginWorking();

        self::assertNull($turns->accept('Second question'));
        self::assertNull($turns->accept('Third question'));
        self::assertSame(
            ['Second question', 'Third question'],
            $turns->queued(),
        );
    }

    public function testTheMessageOfATurnIsHandedOverOnlyOnce(): void
    {
        $turns = new TurnQueue();
        $turns->accept('First question');
        $turns->beginWorking();

        self::assertNull($turns->beginWorking());
    }

    public function testNothingIsHandedOverWhileTheQueueIsEmpty(): void
    {
        $turns = new TurnQueue();

        self::assertNull($turns->beginWorking());
    }

    public function testASettledTurnStartsTheMessagesBehindItInTheOrderTheyCame(): void
    {
        $turns = new TurnQueue();
        $turns->accept('First question');
        $turns->beginWorking();
        $turns->accept('Second question');
        $turns->accept('Third question');

        self::assertSame('Second question', $turns->finishWorking());
        self::assertSame(['Third question'], $turns->queued());
        self::assertSame('Second question', $turns->beginWorking());
        self::assertSame('Third question', $turns->finishWorking());
        self::assertSame([], $turns->queued());
        self::assertSame('Third question', $turns->beginWorking());
    }

    public function testATurnThatSettlesWithNothingBehindItLeavesTheQueueIdle(): void
    {
        $turns = new TurnQueue();
        $turns->accept('First question');
        $turns->beginWorking();

        self::assertNull($turns->finishWorking());
        self::assertSame('Second question', $turns->accept('Second question'));
    }
}
