<?php

declare(strict_types=1);

namespace NeuronTuiDemo;

use NeuronTui\Command\CommandArguments;
use NeuronTui\Command\CommandInterface;
use NeuronTui\Command\SelectionOption;
use NeuronTui\Command\SelectionRequest;
use NeuronTui\Command\CommandControlsInterface;

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

    public function run(CommandControlsInterface $controls, CommandArguments $arguments): void
    {
        if ($arguments->text !== '') {
            $controls->useAgent(DemoAgent::make()->setModelId($arguments->text));
            $controls->say("Model changed to {$arguments->text}.");

            return;
        }

        $controls->requestSelection(new SelectionRequest($this->name(), 'Choose a model', [
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
        ]));
    }
}
