<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Support;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Testing\FakeAIProvider;

final class SelfConfiguredAgent extends Agent
{
    protected function provider(): AIProviderInterface
    {
        return new FakeAIProvider(new AssistantMessage('An answer.'));
    }
}
