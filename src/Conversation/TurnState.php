<?php

declare(strict_types=1);

namespace NeuronCli\Conversation;

/**
 * Where a turn stands between the person writing and the Agent answering.
 *
 * @internal
 */
enum TurnState
{
    /**
     * Nothing is under way: the next message goes to the Agent at once.
     */
    case Idle;

    /**
     * A message has been taken for the Agent but not handed over yet. The
     * person already sees it in the History, so the turn is occupied.
     */
    case Accepted;

    /**
     * The Agent has the message and is answering.
     */
    case Working;
}
