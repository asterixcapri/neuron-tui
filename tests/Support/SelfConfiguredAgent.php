<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Support;

use NeuronAI\Agent\Agent;
use NeuronInteraction\Agent\AgentFactoryRegistry;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Testing\FakeAIProvider;

final class SelfConfiguredAgent extends Agent
{
    public static function registry(): AgentFactoryRegistry
    {
        $registry = new AgentFactoryRegistry();
        $registry->register('test', static fn (): Agent => new self());

        return $registry;
    }

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
