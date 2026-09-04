<?php

declare(strict_types=1);

namespace NeuronTui\Subagent;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\ToolPropertyInterface;

final class SubagentTool extends AbstractSubagentTool
{
    public function __construct(Subagents $subagents)
    {
        parent::__construct(
            'subagent',
            'Start a subagent task in the background.',
            $subagents,
        );
    }

    /** @return list<ToolPropertyInterface> */
    protected function properties(): array
    {
        return [new ToolProperty(
            'task',
            PropertyType::STRING,
            'The task for the subagent.',
            true,
        )];
    }

    /** @return array{id: string, state: string} */
    public function __invoke(string $task): array
    {
        return $this->subagents->start($task);
    }
}
