<?php

declare(strict_types=1);

namespace NeuronTui\Tests\History;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\ChatHistory;
use NeuronInteraction\Session\Session;

/** Opens Neuron's context to seed or clear a stored test conversation. */
final class SessionHistory
{
    public static function of(Session|ChatHistory $session): ChatHistory
    {
        return $session instanceof ChatHistory ? $session : $session->bindToAgent(new Agent())->getChatHistory();
    }
}
