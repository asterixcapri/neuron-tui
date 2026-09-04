<?php

declare(strict_types=1);

namespace NeuronTuiDemo;

use NeuronTui\Command\CommandArguments;
use NeuronTui\Command\CommandInterface;
use NeuronTui\Conversation\ChoiceOption;
use NeuronTui\Command\CommandControls;

final readonly class ModelCommand implements CommandInterface
{
    public function name(): string
    {
        return 'model';
    }

    public function describe(): string
    {
        return 'Changes the AI model.';
    }

    public function run(CommandControls $controls, CommandArguments $arguments): void
    {
        $modelId = $controls->choose('Choose a model', [
            new ChoiceOption(
                'openai:gpt-5.6-sol',
                'OpenAI · GPT-5.6 Sol',
                'Reliable for complex, agentic tasks.',
            ),
            new ChoiceOption(
                'openai:gpt-5.6-terra',
                'OpenAI · GPT-5.6 Terra',
                'Balanced intelligence, speed, and cost.',
            ),
            new ChoiceOption(
                'openai:gpt-5.6-luna',
                'OpenAI · GPT-5.6 Luna',
                'Fast and affordable for everyday tasks.',
            ),
            new ChoiceOption(
                'openai:gpt-5.4-mini',
                'OpenAI · GPT-5.4 Mini',
                'Efficient for focused, high-volume work.',
            ),
            new ChoiceOption(
                'openai:gpt-5.4-nano',
                'OpenAI · GPT-5.4 Nano',
                'Lowest-cost option for simple tasks.',
            ),
            new ChoiceOption(
                'anthropic:claude-opus-5',
                'Anthropic · Claude Opus 5',
                'Most capable Claude for demanding tasks.',
            ),
            new ChoiceOption(
                'anthropic:claude-sonnet-5',
                'Anthropic · Claude Sonnet 5',
                'Balanced intelligence, speed, and cost.',
            ),
            new ChoiceOption(
                'anthropic:claude-haiku-4-5-20251001',
                'Anthropic · Claude Haiku 4.5',
                'Fast and affordable for simple tasks.',
            ),
        ]);

        if ($modelId === null) {
            return;
        }

        $controls->useAgent(DemoAgent::make()->setModelId($modelId));
        $controls->say("Model changed to {$modelId}.");
    }
}
