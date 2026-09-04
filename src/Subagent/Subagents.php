<?php

declare(strict_types=1);

namespace NeuronTui\Subagent;

use InvalidArgumentException;
use LogicException;
use NeuronAI\Agent\Agent;
use NeuronTui\Conversation\ConversationPort;
use NeuronTui\Conversation\SubagentReply;
use Throwable;

use function Amp\async;

/** @internal Owns child identity, scheduling and execution. */
final class Subagents
{
    /** @var array<string, Subagent> */
    private array $subagents = [];

    /** @var list<string> */
    private array $ready = [];

    private int $activeTurns = 0;
    private ?ConversationPort $conversation = null;
    private readonly ChildTurnExecutorInterface $executor;

    /**
     * @param class-string<Agent> $agentClass
     */
    public function __construct(
        private readonly string $agentClass,
        private readonly int $concurrency = 4,
        ?ChildTurnExecutorInterface $executor = null,
    ) {
        if ($concurrency < 1) {
            throw new InvalidArgumentException(
                'Subagent concurrency must be a positive integer.',
            );
        }

        $this->executor = $executor
            ?? new ParallelChildTurnExecutor($concurrency);
    }

    public function connect(ConversationPort $conversation): void
    {
        $this->conversation = $conversation;
    }

    /** @return array{id: string, state: string} */
    public function start(string $task): array
    {
        if (!$this->conversation instanceof ConversationPort) {
            throw new LogicException(
                'The subagent tool is not connected to a conversation.',
            );
        }

        $id = bin2hex(random_bytes(16));
        $this->subagents[$id] = new Subagent(
            $id,
            $this->agentClass,
            SubagentState::Queued,
            $task,
        );
        $this->ready[] = $id;
        $this->dispatch();

        return ['id' => $id, 'state' => $this->subagents[$id]->state->value];
    }

    /** @return array{id: string, state: string, history: list<array<string, mixed>>} */
    public function status(string $id): array
    {
        $subagent = $this->subagents[$id] ?? null;

        if (!$subagent instanceof Subagent) {
            throw new LogicException("Unknown subagent ID: {$id}");
        }

        return [
            'id' => $id,
            'state' => $subagent->state->value,
            'history' => $subagent->history,
        ];
    }

    /** @return array{id: string, state: string} */
    public function send(string $id, string $message): array
    {
        throw new LogicException(
            'Continuing a subagent is not available yet.',
        );
    }

    private function dispatch(): void
    {
        while (
            $this->activeTurns < $this->concurrency
            && $this->ready !== []
        ) {
            $id = array_shift($this->ready);
            $subagent = $this->subagents[$id];
            $subagent->state = SubagentState::Running;
            ++$this->activeTurns;
            $this->execute($subagent, $this->conversation);
        }
    }

    private function execute(
        Subagent $subagent,
        ?ConversationPort $conversation,
    ): void {
        async(function () use ($subagent, $conversation): void {
            try {
                $result = $this->executor
                    ->execute(
                        $subagent->agentClass,
                        $subagent->message,
                        $subagent->history,
                    )
                    ->await();
                $subagent->history = $result->history;
                $subagent->state = SubagentState::Idle;
                $conversation?->deliver(
                    new SubagentReply($subagent->id, $result->reply),
                );
            } catch (Throwable) {
                $subagent->state = SubagentState::Failed;
                $conversation?->deliver(new SubagentReply(
                    $subagent->id,
                    'The subagent failed while processing its turn.',
                ));
            } finally {
                --$this->activeTurns;
                $this->dispatch();
            }
        })->ignore();
    }
}
