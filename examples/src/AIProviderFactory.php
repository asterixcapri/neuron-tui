<?php

declare(strict_types=1);

namespace NeuronTuiDemo;

use NeuronAI\HttpClient\AmpHttpClient;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronAI\Providers\OpenAILike;
use RuntimeException;

final class AIProviderFactory
{
    public static function create(string $modelId): AIProviderInterface
    {
        $parts = explode(':', $modelId, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new \InvalidArgumentException('Model ID must use the provider:model format.');
        }

        [$provider, $model] = $parts;

        if ($provider === 'openai') {
            $key = $_ENV['OPENAI_API_KEY'] ?? null;

            if (!is_string($key) || $key === '') {
                throw new RuntimeException('OPENAI_API_KEY not configured');
            }

            return new OpenAIResponses(
                key: $key,
                model: $model,
                httpClient: new AmpHttpClient(),
            );
        } elseif ($provider === 'anthropic') {
            $key = $_ENV['ANTHROPIC_API_KEY'] ?? null;

            if (!is_string($key) || $key === '') {
                throw new RuntimeException('ANTHROPIC_API_KEY not configured');
            }

            return new Anthropic(
                key: $key,
                model: $model,
                httpClient: new AmpHttpClient(),
            );
        } elseif ($provider === 'zen') {
            $key = $_ENV['OPENCODE_ZEN_API_KEY'] ?? null;

            if (!is_string($key) || $key === '') {
                throw new RuntimeException('OPENCODE_ZEN_API_KEY not configured');
            }

            return new OpenAILike(
                baseUri: 'https://opencode.ai/zen/v1',
                key: $key,
                model: $model,
                httpClient: new AmpHttpClient(),
            );
        } else {
            throw new RuntimeException("Unknown provider: {$provider}.");
        }
    }
}
