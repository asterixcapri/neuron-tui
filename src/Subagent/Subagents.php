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
    private ?string $cancellationSubscription = null;
    private int $sessionGeneration = 0;
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
        if ($conversation->cancellation()->isRequested()) {
            throw new LogicException(
                'The subagent tool cannot connect to a closed conversation.',
            );
        }

        if ($this->conversation === $conversation) {
            return;
        }

        if ($this->conversation instanceof ConversationPort) {
            $this->forgetSession();
        }

        $this->conversation = $conversation;
        $generation = ++$this->sessionGeneration;
        $this->cancellationSubscription = $conversation
            ->cancellation()
            ->subscribe(function () use ($conversation, $generation): void {
                if (
                    $this->conversation !== $conversation
                    || $this->sessionGeneration !== $generation
                ) {
                    return;
                }

                $this->forgetSession();
            });
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
            startedAt: microtime(true),
        );
        $this->ready[] = $id;
        $this->dispatch();

        return ['id' => $id, 'state' => $this->subagents[$id]->state->value];
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
    public function status(string $id): array
    {
        $subagent = $this->subagents[$id] ?? null;

        if (!$subagent instanceof Subagent) {
            throw new LogicException("Unknown subagent ID: {$id}");
        }

        $status = [
            'id' => $id,
            'state' => $subagent->state->value,
            'queued_messages' => $subagent->queuedMessages(),
            'history' => $subagent->history,
        ];

        if ($subagent->startedAt !== null) {
            $status['elapsed_seconds'] = microtime(true) - $subagent->startedAt;
        }

        return $status;
    }

    /** @return array{id: string, state: string} */
    public function send(string $id, string $message): array
    {
        $subagent = $this->subagents[$id] ?? null;

        if (!$subagent instanceof Subagent) {
            throw new LogicException("Unknown subagent ID: {$id}");
        }

        if ($subagent->state === SubagentState::Failed) {
            throw new LogicException(
                "Subagent {$id} has failed; create a new subagent to retry.",
            );
        }

        $subagent->enqueue($message);

        if ($subagent->state === SubagentState::Idle) {
            $subagent->state = SubagentState::Queued;
            $subagent->startedAt = microtime(true);
            $this->ready[] = $id;
            $this->dispatch();
        }

        return ['id' => $id, 'state' => $subagent->state->value];
    }

    private function dispatch(): void
    {
        $conversation = $this->conversation;

        if (!$conversation instanceof ConversationPort) {
            return;
        }

        while (
            $this->activeTurns < $this->concurrency
            && $this->ready !== []
        ) {
            $id = array_shift($this->ready);
            $subagent = $this->subagents[$id] ?? null;

            if (!$subagent instanceof Subagent) {
                continue;
            }

            $message = $subagent->nextMessage();
            $subagent->state = SubagentState::Running;
            ++$this->activeTurns;
            $this->execute(
                $subagent,
                $message,
                $conversation,
                $this->sessionGeneration,
            );
        }
    }

    private function execute(
        Subagent $subagent,
        string $message,
        ConversationPort $conversation,
        int $generation,
    ): void {
        async(function () use (
            $subagent,
            $message,
            $conversation,
            $generation,
        ): void {
            try {
                $result = $this->executor
                    ->execute(
                        new ChildTurn(
                            $subagent->agentClass,
                            $message,
                            $subagent->history,
                        ),
                        $conversation->cancellation(),
                    )
                    ->await($conversation->cancellation());
            } catch (Throwable) {
                if (!$this->owns($subagent, $conversation, $generation)) {
                    return;
                }

                --$this->activeTurns;
                $this->fail($subagent, $conversation);
                $this->dispatch();

                return;
            }

            if (!$this->owns($subagent, $conversation, $generation)) {
                return;
            }

            $subagent->history = $result->history;
            $subagent->state = SubagentState::Idle;
            $subagent->startedAt = null;
            --$this->activeTurns;
            $conversation->deliver(
                new SubagentReply($subagent->id, $result->reply),
            );

            $this->queueNextTurn($subagent);
            $this->dispatch();
        })->ignore();
    }

    private function owns(
        Subagent $subagent,
        ConversationPort $conversation,
        int $generation,
    ): bool {
        return $this->conversation === $conversation
            && $this->sessionGeneration === $generation
            && !$conversation->cancellation()->isRequested()
            && ($this->subagents[$subagent->id] ?? null) === $subagent;
    }

    private function fail(
        Subagent $subagent,
        ConversationPort $conversation,
    ): void {
        $subagent->state = SubagentState::Failed;
        $subagent->startedAt = null;
        $subagent->clearQueuedMessages();
        $conversation->deliver(new SubagentReply(
            $subagent->id,
            'The subagent failed while processing its turn.',
        ));
    }

    private function forgetSession(): void
    {
        if (
            $this->conversation instanceof ConversationPort
            && $this->cancellationSubscription !== null
        ) {
            $this->conversation
                ->cancellation()
                ->unsubscribe($this->cancellationSubscription);
        }

        ++$this->sessionGeneration;
        $this->conversation = null;
        $this->cancellationSubscription = null;
        $this->ready = [];
        $this->subagents = [];
        $this->activeTurns = 0;
        $this->executor->cancel();
    }

    private function queueNextTurn(Subagent $subagent): void
    {
        if (
            $subagent->state !== SubagentState::Idle
            || !$subagent->hasQueuedMessages()
        ) {
            return;
        }

        $subagent->state = SubagentState::Queued;
        $subagent->startedAt = microtime(true);
        $this->ready[] = $subagent->id;
    }
}
