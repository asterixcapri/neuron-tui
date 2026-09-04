<?php

declare(strict_types=1);

namespace NeuronTui\Subagent;

use Amp\Future;
use NeuronAI\Agent\Agent;

/** @internal */
interface ChildTurnExecutorInterface
{
    /**
     * @param class-string<Agent> $agentClass
     * @param list<array<string, mixed>> $history
     * @return Future<ChildTurnResult>
     */
    public function execute(
        string $agentClass,
        string $message,
        array $history,
    ): Future;
}
