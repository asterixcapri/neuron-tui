<?php

declare(strict_types=1);

namespace NeuronTui\Subagent;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\ToolPropertyInterface;

final class SubagentStatusTool extends AbstractSubagentTool
{
    public function __construct(Subagents $subagents)
    {
        parent::__construct(
            'subagent_status',
            'Read the current state of a subagent.',
            $subagents,
        );
    }

    /** @return list<ToolPropertyInterface> */
    protected function properties(): array
    {
        return [new ToolProperty(
            'subagent_id',
            PropertyType::STRING,
            'The subagent ID.',
            true,
        )];
    }

    /**
     * @return array{
     *     id: string,
     *     state: string,
     *     queued_messages: int,
     *     history: list<array<string, mixed>>,
     *     elapsed_seconds?: float
     * }
     */
    public function __invoke(string $subagent_id): array
    {
        return $this->subagents->status($subagent_id);
    }
}
