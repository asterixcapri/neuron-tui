<?php

declare(strict_types=1);

namespace NeuronTui\Subagent;

use LogicException;
use NeuronAI\Agent\Agent;

/** @internal */
final class Subagent
{
    /** @var list<string> */
    private array $messages;

    /**
     * @param class-string<Agent> $agentClass
     * @param list<array<string, mixed>> $history
     */
    public function __construct(
        public readonly string $id,
        public readonly string $agentClass,
        public SubagentState $state,
        string $message,
        public array $history = [],
        public ?float $startedAt = null,
    ) {
        $this->messages = [$message];
    }

    public function enqueue(string $message): void
    {
        $this->messages[] = $message;
    }

    public function nextMessage(): string
    {
        $message = array_shift($this->messages);

        if (!is_string($message)) {
            throw new LogicException('A queued subagent has no message to process.');
        }

        return $message;
    }

    public function queuedMessages(): int
    {
        return count($this->messages);
    }

    public function hasQueuedMessages(): bool
    {
        return $this->messages !== [];
    }

    public function clearQueuedMessages(): void
    {
        $this->messages = [];
    }
}
