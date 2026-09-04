<?php

declare(strict_types=1);

namespace NeuronTui\Conversation;

use Amp\Cancellation;
use Amp\DeferredCancellation;
use Closure;

/**
 * The Session-scoped return address for delayed Subagent replies.
 *
 * It deliberately knows nothing about Subagent identity or execution. A
 * source may deliver complete replies until the owning Session closes it.
 */
final class ConversationPort
{
    private readonly DeferredCancellation $closed;

    private bool $isClosed = false;

    /**
     * @param Closure(SubagentReply): void $deliver
     *
     * @internal the Conversation Runtime owns ports
     */
    public function __construct(private readonly Closure $deliver)
    {
        $this->closed = new DeferredCancellation();
    }

    /**
     * Delivers a new input, or rejects it when its Session is no longer live.
     */
    public function deliver(SubagentReply $reply): bool
    {
        if ($this->isClosed) {
            return false;
        }

        ($this->deliver)($reply);

        return true;
    }

    /**
     * Cancellation observed by resources whose work belongs to this Session.
     */
    public function cancellation(): Cancellation
    {
        return $this->closed->getCancellation();
    }

    public function close(): void
    {
        if ($this->isClosed) {
            return;
        }

        $this->isClosed = true;
        $this->closed->cancel();
    }
}
