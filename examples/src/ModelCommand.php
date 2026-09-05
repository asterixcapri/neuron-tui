<?php

declare(strict_types=1);

namespace NeuronTuiDemo;

use NeuronInteraction\Command\CommandArguments;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\SelectionOption;
use NeuronInteraction\Command\SelectionRequest;
use NeuronInteraction\Command\CommandAdapterInterface;

final readonly class ModelCommand implements CommandInterface
{
    public function name(): string
    {
        return '/model';
    }

    public function describe(): string
    {
        return 'Changes the AI model.';
    }

    /** @param CommandAdapterInterface<mixed> $adapter */
    public function run(CommandAdapterInterface $adapter, CommandArguments $arguments): void
    {
        if ($arguments->text !== '') {
            $adapter->useAgent(DemoAgent::make()->setModelId($arguments->text));
            $adapter->say("Model changed to {$arguments->text}.");

            return;
        }

        $adapter->requestSelection(new SelectionRequest($this->name(), 'Choose a model', [
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
