<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Subagent\Fixture;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Testing\FakeAIProvider;

final class WorkerAgent extends Agent
{
    protected function provider(): AIProviderInterface
    {
        fwrite(STDOUT, 'worker stdout must stay private');
        fwrite(STDERR, 'worker stderr must stay private');

        return new FakeAIProvider(
            new AssistantMessage('Background result.'),
        );
    }
}
