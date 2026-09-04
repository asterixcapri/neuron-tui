<?php

declare(strict_types=1);

namespace NeuronTui\Subagent;

use InvalidArgumentException;
use NeuronAI\Agent\Agent;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\AbstractToolkit;

final class SubagentToolkit extends AbstractToolkit
{
    private readonly Subagents $subagents;

    public function __construct(string $agentClass, int $concurrency = 4)
    {
        if (!is_a($agentClass, Agent::class, true)) {
            throw new InvalidArgumentException(
                'The subagent class must extend '.Agent::class.'.',
            );
        }

        if ($concurrency < 1) {
            throw new InvalidArgumentException(
                'Subagent concurrency must be a positive integer.',
            );
        }

        $this->subagents = new Subagents($agentClass);
    }

    /** @return list<ToolInterface> */
    public function provide(): array
    {
        return [
            new SubagentTool($this->subagents),
            new SubagentSendTool($this->subagents),
            new SubagentStatusTool($this->subagents),
        ];
    }
}
