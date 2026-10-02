<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Turn;

use Closure;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronTui\Turn\TurnRenderer;
use NeuronTui\View\ConversationView;

/** Connects native Neuron streaming to terminal presentation. @internal */
final class TurnRunner
{
    public function __construct(private readonly ConversationView $view) {}

    /** @param (Closure(): bool)|null $responseWasStopped */
    public function run(Agent $agent, UserMessage $message, ?Closure $responseWasStopped = null): bool
    {
        return (new TurnRenderer($this->view))->run($agent->stream($message), $responseWasStopped);
    }
}
