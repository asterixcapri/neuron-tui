<?php

declare(strict_types=1);

namespace NeuronTui\Subagent;

final readonly class ChildTurnResult
{
    /**
     * @param list<array<string, mixed>> $history
     */
    public function __construct(
        public string $reply,
        public array $history,
    ) {
    }
}
