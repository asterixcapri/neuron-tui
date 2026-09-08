<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Support;

use Closure;
use NeuronAI\Agent\Agent;
use NeuronAI\Providers\AIProviderInterface;

final class ConfiguredAgent extends Agent
{
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
