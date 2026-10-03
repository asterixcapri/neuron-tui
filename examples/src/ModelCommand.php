<?php

declare(strict_types=1);

namespace NeuronTuiDemo;

use NeuronAI\HttpClient\HttpClientInterface;
use NeuronInteraction\Command\CommandContext;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\SelectionOption;
use NeuronInteraction\Command\SelectionRequest;

final readonly class ModelCommand implements CommandInterface
{
    public function __construct(private ?HttpClientInterface $httpClient = null) {}

    public function name(): string
    {
        return '/model';
    }

    public function describe(): string
    {
        return 'Changes the AI model.';
    }

    public function run(CommandContext $context, string $value): void
    {
        if ($value === '') {
            $context->requestSelection(new SelectionRequest($this->name(), 'Choose a model', [
                new SelectionOption(
                    'openai:gpt-5.6-sol',
                    'OpenAI · GPT-5.6 Sol',
                    'Reliable for complex, agentic tasks.',
                ),
                new SelectionOption(
                    'openai:gpt-5.6-terra',
                    'OpenAI · GPT-5.6 Terra',
                    'Balanced intelligence, speed, and cost.',
                ),
                new SelectionOption(
                    'openai:gpt-5.6-luna',
                    'OpenAI · GPT-5.6 Luna',
                    'Fast and affordable for everyday tasks.',
                ),
                new SelectionOption(
                    'openai:gpt-5.4-mini',
                    'OpenAI · GPT-5.4 Mini',
                    'Efficient for focused, high-volume work.',
                ),
                new SelectionOption(
                    'openai:gpt-5.4-nano',
                    'OpenAI · GPT-5.4 Nano',
                    'Lowest-cost option for simple tasks.',
                ),
                new SelectionOption(
                    'anthropic:claude-opus-5',
                    'Anthropic · Claude Opus 5',
                    'Most capable Claude for demanding tasks.',
                ),
                new SelectionOption(
                    'anthropic:claude-sonnet-5',
                    'Anthropic · Claude Sonnet 5',
                    'Balanced intelligence, speed, and cost.',
                ),
                new SelectionOption(
                    'anthropic:claude-haiku-4-5-20251001',
                    'Anthropic · Claude Haiku 4.5',
                    'Fast and affordable for simple tasks.',
                ),
                new SelectionOption(
                    'zen:deepseek-v4-flash',
                    'OpenCode Zen · DeepSeek V4 Flash',
                    'Fast, efficient reasoning for everyday agent tasks.',
                ),
                new SelectionOption(
                    'zen:deepseek-v4-pro',
                    'OpenCode Zen · DeepSeek V4 Pro',
                    'Advanced reasoning and coding for demanding agent tasks.',
                ),
                new SelectionOption(
                    'zen:glm-5.3-flash',
                    'OpenCode Zen · GLM 5.3 Flash',
                    'Efficient coding and visual understanding for agent workflows.',
                ),
                new SelectionOption(
                    'zen:glm-5.3',
                    'OpenCode Zen · GLM 5.3',
                    'Flagship for complex codebases and long-running agent tasks.',
                ),
            ]));

            return;
        }

        $provider = AIProviderFactory::create($value, $this->httpClient);
        $context->agent()->setAiProvider($provider);
        $context->configurationStore()->write('model', $value);
        $context->notify("Model changed to {$value}.");
    }
}
