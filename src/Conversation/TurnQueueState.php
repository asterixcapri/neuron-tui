<?php

declare(strict_types=1);

namespace NeuronTui\Conversation;

/**
 * The queue state from Turn preparation through execution completion.
 *
 * @internal
 */
enum TurnQueueState
{
    /**
     * No current Turn occupies the conversation.
     */
    case Idle;

    /**
     * A current message has been selected but not handed over for execution.
     * The Turn already occupies the conversation.
     */
    case Ready;

    /**
     * The message has been handed over for execution and completion has not
     * yet been acknowledged. The asynchronous callback need not be executing
     * at this exact instant.
     */
    case Running;
}
