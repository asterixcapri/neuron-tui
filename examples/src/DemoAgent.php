<?php

declare(strict_types=1);

namespace NeuronTuiDemo;

use NeuronAI\Agent\Agent;
use NeuronAI\HttpClient\AmpHttpClient;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronAI\Tools\Toolkits\Calendar\CalendarToolkit;
use NeuronAI\Tools\Toolkits\FileSystem\FileSystemToolkit;
use NeuronAI\Tools\Toolkits\Jina\JinaToolkit;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use NeuronTui\Subagent\SubagentToolkit;
use RuntimeException;

class DemoAgent extends Agent
{
    private string $modelId = 'openai:gpt-5.4-nano';

    public function __construct()
    {
        parent::__construct();

        $this->toolMaxRuns(PHP_INT_MAX);
    }

    public function setModelId(string $modelId): static
    {
        $this->modelId = $modelId;

        return $this;
    }

    protected function provider(): AIProviderInterface
    {
        [$provider, $model] = explode(':', $this->modelId, 2);

        if ($provider === 'openai') {
            $key = self::environment('OPENAI_API_KEY');

            if ($key === null) {
                throw new RuntimeException('OPENAI_API_KEY not configured');
            }

            return new OpenAIResponses(
                key: $key,
                model: $model,
                httpClient: new AmpHttpClient(),
            );
        } elseif ($provider === 'anthropic') {
            $key = self::environment('ANTHROPIC_API_KEY');

            if ($key === null) {
                throw new RuntimeException('ANTHROPIC_API_KEY not configured');
            }

            return new Anthropic(
                key: $key,
                model: $model,
                httpClient: new AmpHttpClient(),
            );
        } else {
            throw new RuntimeException("Unknown provider: {$provider}.");
        }
    }

    protected function tools(): array
    {
        return [
            ...$this->demoTools(),
            new SubagentToolkit(DemoSubagent::class),
        ];
    }

    /** @return list<ToolkitInterface> */
    protected function demoTools(): array
    {
        $tools = [
            new FileSystemToolkit(),
            new CalendarToolkit(),
        ];

        $jinaKey = self::environment('JINA_API_KEY');

        if ($jinaKey !== null) {
            $tools[] = new JinaToolkit($jinaKey);
        }

        return $tools;
    }

    private static function environment(string $name): ?string
    {
        $value = getenv($name);

        if (is_string($value) && $value !== '') {
            return $value;
        }

        $value = $_ENV[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
