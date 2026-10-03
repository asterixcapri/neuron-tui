<?php

declare(strict_types=1);

namespace NeuronTui\Tests\History;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronInteraction\Conversation;
use NeuronInteraction\Session\Session;
use NeuronInteraction\Session\SessionStore;

use function iterator_to_array;

/** Creates stored test turns through the same public flow used by clients. */
final class StoredConversation
{
    public static function turn(SessionStore $store, Session $session, UserMessage $question, Message $answer = new AssistantMessage('Stored answer.')): void
    {
        $agent = (new Agent())->setAiProvider(new FakeAIProvider($answer));
        $conversation = new Conversation($agent, $store, session: $session);
        iterator_to_array($conversation->submitInput($question));
    }
}
