<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Support;

use NeuronAI\Agent\Agent;
use NeuronInteraction\Agent\AgentFactoryRegistry;
use NeuronInteraction\Agent\ConfiguredAgentInterface;
use NeuronInteraction\Configuration\ConfigurationStore;
use ReflectionClass;

/** Supplies the prepared initial Agent used by terminal behavior tests. */
class TestAgent extends Agent implements ConfiguredAgentInterface
{
    /** @var array<class-string<self>, self> */
    private static array $initialAgents = [];

    public static function registryForInitialAgent(Agent $initialAgent): AgentFactoryRegistry
    {
        if (!$initialAgent instanceof self) {
            throw new \LogicException('Terminal fixtures must extend TestAgent.');
        }
        if (isset(self::$initialAgents[$initialAgent::class])) {
            throw new \LogicException('An initial Agent of this class is already waiting for Tui construction.');
        }
        self::$initialAgents[$initialAgent::class] = $initialAgent;
        $registry = new AgentFactoryRegistry();
        $registry->register('test', $initialAgent::class);
        return $registry;
    }

    public static function createAgent(ConfigurationStore $configurationStore): static
    {
        $initial = self::$initialAgents[static::class] ?? null;
        unset(self::$initialAgents[static::class]);
        if ($initial instanceof static) {
            return $initial;
        }
        return static::freshAgent($configurationStore);
    }

    protected function provider(): \NeuronAI\Providers\AIProviderInterface
    {
        return new \NeuronAI\Testing\FakeAIProvider(new \NeuronAI\Chat\Messages\AssistantMessage('An answer.'));
    }

    protected static function freshAgent(ConfigurationStore $configurationStore): static
    {
        return (new ReflectionClass(static::class))->newInstance();
    }
}
