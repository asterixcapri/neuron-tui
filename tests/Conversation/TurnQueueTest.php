<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Conversation;

use NeuronAI\Chat\Messages\UserMessage;
use NeuronTui\Conversation\TurnQueue;
use PHPUnit\Framework\TestCase;

final class TurnQueueTest extends TestCase
{
    public function testTheFirstMessagePreparesATurnStraightAway(): void
    {
        $turns = new TurnQueue();
        $first = new UserMessage('First question');

        self::assertSame($first, $turns->accept($first));
        self::assertSame([], $turns->queuedMessages());
        self::assertSame($first, $turns->takeForExecution());
    }

    public function testAMessageReadyForExecutionAlreadyOccupiesTheTurn(): void
    {
        $turns = new TurnQueue();
        $first = new UserMessage('First question');
        $second = new UserMessage('Second question');
        $turns->accept($first);

        self::assertNull($turns->accept($second));
        self::assertSame([$second], $turns->queuedMessages());
    }

    public function testAMessageArrivingWhileTheAgentWorksWaitsBehindTheTurn(): void
    {
        $turns = new TurnQueue();
        $first = new UserMessage('First question');
        $second = new UserMessage('Second question');
        $third = new UserMessage('Third question');
        $turns->accept($first);
        $turns->takeForExecution();

        self::assertNull($turns->accept($second));
        self::assertNull($turns->accept($third));
        self::assertSame(
            [$second, $third],
            $turns->queuedMessages(),
        );
    }

    public function testTheMessageOfATurnIsHandedOverOnlyOnce(): void
    {
        $turns = new TurnQueue();
        $first = new UserMessage('First question');
        $turns->accept($first);
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
        $first = new UserMessage('First question');
        $second = new UserMessage('Second question');
        $third = new UserMessage('Third question');
        $turns->accept($first);
        $turns->takeForExecution();
        $turns->accept($second);
        $turns->accept($third);

        self::assertSame($second, $turns->finishAndAdvance());
        self::assertSame([$third], $turns->queuedMessages());
        self::assertSame($second, $turns->takeForExecution());
        self::assertSame($third, $turns->finishAndAdvance());
        self::assertSame([], $turns->queuedMessages());
        self::assertSame($third, $turns->takeForExecution());
    }

    public function testATurnThatSettlesWithNothingBehindItLeavesTheQueueIdle(): void
    {
        $turns = new TurnQueue();
        $first = new UserMessage('First question');
        $second = new UserMessage('Second question');
        $turns->accept($first);
        $turns->takeForExecution();

        self::assertNull($turns->finishAndAdvance());
        self::assertSame($second, $turns->accept($second));
    }
}
