<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Support;

use Closure;
use NeuronAI\Providers\AIProviderInterface;

final class ConfiguredAgent extends TestAgent
{
    protected static function freshAgent(\NeuronInteraction\Configuration\ConfigurationStore $configurationStore): static
    {
        $configuration = $configurationStore->read('agent');
        $model = $configuration?->get('model');
        $capability = $configuration?->get('capability');
        if (!is_string($model) || !is_string($capability)) {
            throw new \RuntimeException('Model and capability must be strings.');
        }
        return (new static(
            static fn (string $option): \NeuronAI\Testing\FakeAIProvider => new \NeuronAI\Testing\FakeAIProvider(
                new \NeuronAI\Chat\Messages\AssistantMessage($model . ' / ' . $option),
            ),
        ))->setCapability($capability);
    }

    private string $capability = '';

    /** @param Closure(string): AIProviderInterface $providerFactory */
    public function __construct(private readonly Closure $providerFactory)
    {
        parent::__construct();
    }

    public function setCapability(string $capability): self
    {
        $this->capability = $capability;

        return $this;
    }

    protected function provider(): AIProviderInterface
    {
        return ($this->providerFactory)($this->capability);
    }
}
