<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Support;

use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Testing\FakeAIProvider;

final class SelfConfiguredAgent extends TestAgent
{

    public static function configurationStore(): ConfigurationStore
    {
        $store = new ConfigurationStore(new InMemoryStorage(), 'test-user');
        $store->create('global', ['agent' => 'test']);

        return $store;
    }

    protected function provider(): AIProviderInterface
    {
        return new FakeAIProvider(new AssistantMessage('An answer.'));
    }
}
