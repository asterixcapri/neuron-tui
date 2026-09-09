<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Conversation;

use NeuronTui\Conversation\TurnQueue;
use PHPUnit\Framework\TestCase;

final class TurnQueueTest extends TestCase
{
    public function testTheFirstMessagePreparesATurnStraightAway(): void
    {
        $turns = new TurnQueue();

        self::assertSame('First question', $turns->accept('First question'));
        self::assertSame([], $turns->queuedMessages());
        self::assertSame('First question', $turns->takeForExecution());
    }

    public function testAMessageReadyForExecutionAlreadyOccupiesTheTurn(): void
    {
        $turns = new TurnQueue();
        $turns->accept('First question');

        self::assertNull($turns->accept('Second question'));
        self::assertSame(['Second question'], $turns->queuedMessages());
    }

    public function testAMessageArrivingWhileTheAgentWorksWaitsBehindTheTurn(): void
    {
        $turns = new TurnQueue();
        $turns->accept('First question');
        $turns->takeForExecution();

        self::assertNull($turns->accept('Second question'));
        self::assertNull($turns->accept('Third question'));
        self::assertSame(
            ['Second question', 'Third question'],
            $turns->queuedMessages(),
        );
    }

    public function testTheMessageOfATurnIsHandedOverOnlyOnce(): void
    {
        $turns = new TurnQueue();
        $turns->accept('First question');
        $turns->takeForExecution();

        self::assertNull($turns->takeForExecution());
    }

    public function testNothingIsHandedOverWhileTheQueueIsEmpty(): void
    {
        $turns = new TurnQueue();

        self::assertNull($turns->takeForExecution());
    }

    public function testACompletedTurnPreparesWaitingMessagesInOrder(): void
    {
        $turns = new TurnQueue();
        $turns->accept('First question');
        $turns->takeForExecution();
        $turns->accept('Second question');
        $turns->accept('Third question');

        self::assertSame('Second question', $turns->finishAndAdvance());
        self::assertSame(['Third question'], $turns->queuedMessages());
        self::assertSame('Second question', $turns->takeForExecution());
        self::assertSame('Third question', $turns->finishAndAdvance());
        self::assertSame([], $turns->queuedMessages());
        self::assertSame('Third question', $turns->takeForExecution());
    }

    public function testATurnThatSettlesWithNothingBehindItLeavesTheQueueIdle(): void
    {
        $turns = new TurnQueue();
        $turns->accept('First question');
        $turns->takeForExecution();

        self::assertNull($turns->finishAndAdvance());
        self::assertSame('Second question', $turns->accept('Second question'));
    }
}
