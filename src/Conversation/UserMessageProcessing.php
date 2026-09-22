<?php

declare(strict_types=1);

namespace NeuronTui\Conversation;

use NeuronTui\UserMessageProcessorInterface;

/** @internal */
final readonly class UserMessageProcessing implements UserMessageProcessorInterface
{
    /** @param list<UserMessageProcessorInterface> $processors */
    public function __construct(private array $processors = [])
    {
    }

    public function forAgent(string $input): string
    {
        foreach ($this->processors as $processor) {
            $input = $processor->forAgent($input);
        }

        return $input;
    }

    public function forDisplay(string $content): string
    {
        foreach (array_reverse($this->processors) as $processor) {
            $content = $processor->forDisplay($content);
        }

        return $content;
    }
}
