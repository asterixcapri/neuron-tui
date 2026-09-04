<?php

declare(strict_types=1);

namespace NeuronTui\Subagent;

use NeuronAI\Agent\Agent;

/** @internal */
final class Subagent
{
    /**
     * @param class-string<Agent> $agentClass
     * @param list<array<string, mixed>> $history
     */
    public function __construct(
        public readonly string $id,
        public readonly string $agentClass,
        public SubagentState $state,
        public readonly string $message,
        public array $history = [],
    ) {
    }
}
