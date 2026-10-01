<?php

declare(strict_types=1);

namespace NeuronTui\Conversation;

use Closure;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronChatCore\Conversation\InProcessEventPublisher;
use NeuronChatCore\Conversation\TurnStream;
use NeuronTui\View\ConversationView;

/** Connects core stream events to terminal presentation. @internal */
final class TurnRunner
{
    public function __construct(private readonly ConversationView $view) {}

    /** @param (Closure(): bool)|null $responseWasStopped */
    public function run(Agent $agent, UserMessage $message, ?Closure $responseWasStopped = null): bool
    {
        $publisher = new InProcessEventPublisher();
        $renderer = new TurnEventRenderer($this->view);
        $publisher->subscribe($renderer->consume(...));

        return (new TurnStream($publisher))->run($agent, $message, $responseWasStopped);
    }
}
