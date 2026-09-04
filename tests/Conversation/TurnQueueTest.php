<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Conversation;

use NeuronTui\Conversation\MessageForAgent;
use NeuronTui\Conversation\SubagentReply;
use NeuronTui\Conversation\TurnQueue;
use PHPUnit\Framework\TestCase;

final class TurnQueueTest extends TestCase
{
    public function testTheFirstMessageStartsATurnStraightAway(): void
    {
        $turns = new TurnQueue();

        $first = new MessageForAgent('First question');

        self::assertSame($first, $turns->accept($first));
        self::assertSame([], $turns->queued());
        self::assertSame($first, $turns->beginWorking());
    }

    public function testAMessageAcceptedButNotYetSentAlreadyOccupiesTheTurn(): void
    {
        $turns = new TurnQueue();
        $turns->accept(new MessageForAgent('First question'));

        $second = new MessageForAgent('Second question');
        self::assertNull($turns->accept($second));
        self::assertSame([$second], $turns->queued());
    }

    public function testAMessageArrivingWhileTheAgentWorksWaitsBehindTheTurn(): void
    {
        $turns = new TurnQueue();
        $turns->accept(new MessageForAgent('First question'));
        $turns->beginWorking();

        $second = new MessageForAgent('Second question');
        $third = new MessageForAgent('Third question');
        self::assertNull($turns->accept($second));
        self::assertNull($turns->accept($third));
        self::assertSame(
            [$second, $third],
            $turns->queued(),
        );
    }

    public function testTheMessageOfATurnIsHandedOverOnlyOnce(): void
    {
        $turns = new TurnQueue();
        $turns->accept(new MessageForAgent('First question'));
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
        $turns->accept(new MessageForAgent('First question'));
        $turns->beginWorking();
        $second = new MessageForAgent('Second question');
        $third = new MessageForAgent('Third question');
        $turns->accept($second);
        $turns->accept($third);

        self::assertSame($second, $turns->finishWorking());
        self::assertSame([$third], $turns->queued());
        self::assertSame($second, $turns->beginWorking());
        self::assertSame($third, $turns->finishWorking());
        self::assertSame([], $turns->queued());
        self::assertSame($third, $turns->beginWorking());
    }

    public function testATurnThatSettlesWithNothingBehindItLeavesTheQueueIdle(): void
    {
        $turns = new TurnQueue();
        $turns->accept(new MessageForAgent('First question'));
        $turns->beginWorking();

        self::assertNull($turns->finishWorking());
        $second = new MessageForAgent('Second question');
        self::assertSame($second, $turns->accept($second));
    }

    public function testPersonMessagesAndSubagentRepliesShareOneOrder(): void
    {
        $turns = new TurnQueue();
        $active = new MessageForAgent('First question');
        $person = new MessageForAgent('Second question');
        $reply = new SubagentReply('child-7', 'The delegated result.');
        $laterPerson = new MessageForAgent('Third question');

        $turns->accept($active);
        $turns->beginWorking();
        $turns->accept($person);
        $turns->accept($reply);
        $turns->accept($laterPerson);

        self::assertSame([$person, $reply, $laterPerson], $turns->queued());
        self::assertSame($person, $turns->finishWorking());
        $turns->beginWorking();
        self::assertSame($reply, $turns->finishWorking());
        $turns->beginWorking();
        self::assertSame($laterPerson, $turns->finishWorking());
    }
}
