<?php

declare(strict_types=1);

namespace NeuronTui\Subagent;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\ToolPropertyInterface;

final class SubagentSendTool extends AbstractSubagentTool
{
    public function __construct(Subagents $subagents)
    {
        parent::__construct(
            'subagent_send',
            'Send another message to an existing subagent.',
            $subagents,
        );
    }

    /** @return list<ToolPropertyInterface> */
    protected function properties(): array
    {
        return [
            new ToolProperty('subagent_id', PropertyType::STRING, 'The subagent ID.', true),
            new ToolProperty('message', PropertyType::STRING, 'The next message.', true),
        ];
    }

    /** @return array{id: string, state: string} */
    public function __invoke(string $subagent_id, string $message): array
    {
        return $this->subagents->send($subagent_id, $message);
    }
}
