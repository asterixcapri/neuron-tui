<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Conversation;

use NeuronTui\Conversation\ConversationPort;
use NeuronTui\Conversation\SubagentReply;
use PHPUnit\Framework\TestCase;

final class ConversationPortTest extends TestCase
{
    public function testItDeliversACompleteReply(): void
    {
        $delivered = [];
        $port = new ConversationPort(
            static function (SubagentReply $reply) use (&$delivered): void {
                $delivered[] = $reply;
            },
        );
        $reply = new SubagentReply('child-4', "First line.\nSecond line.");

        self::assertTrue($port->deliver($reply));
        self::assertSame([$reply], $delivered);
    }

    public function testAClosedPortRejectsLateRepliesAndSignalsCancellation(): void
    {
        $delivered = [];
        $port = new ConversationPort(
            static function (SubagentReply $reply) use (&$delivered): void {
                $delivered[] = $reply;
            },
        );
        $cancellation = $port->cancellation();

        $port->close();

        self::assertTrue($cancellation->isRequested());
        self::assertFalse(
            $port->deliver(new SubagentReply('child-4', 'Too late.')),
        );
        self::assertSame([], $delivered);
    }
}
