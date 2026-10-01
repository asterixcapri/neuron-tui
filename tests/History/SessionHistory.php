<?php

declare(strict_types=1);

namespace NeuronTui\Tests\History;

use NeuronAI\Chat\History\ChatHistory;
use NeuronChatCore\Session\Session;

/** Opens Neuron's context to seed or clear a stored test conversation. */
final class SessionHistory
{
    public static function of(Session|ChatHistory $session): ChatHistory
    {
        return $session instanceof ChatHistory ? $session : new ChatHistory($session->messageStore(), $session->getKey());
    }
}
