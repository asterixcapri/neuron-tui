<?php

declare(strict_types=1);

namespace NeuronTui\Subagent;

use NeuronAI\Agent\Agent;

/**
 * The serializable input required to execute one child Turn.
 *
 * @internal
 */
final readonly class ChildTurn
{
    /**
     * @param class-string<Agent> $agentClass
     * @param list<array<string, mixed>> $history
     */
    public function __construct(
        public string $agentClass,
        public string $message,
        public array $history,
    ) {}
}
