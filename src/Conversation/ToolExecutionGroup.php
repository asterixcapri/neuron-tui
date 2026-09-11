<?php

declare(strict_types=1);

namespace NeuronTui\Conversation;

use NeuronAI\Agent\Nodes\ParallelToolNode;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Tools\ToolInterface;
use NeuronTui\History\ToolCallCorrelation;
use NeuronTui\History\ToolOutcome;
use Spatie\Fork\Fork;
use Throwable;

/** Tracks actual outcomes separately from Neuron's tool announcements. @internal */
final class ToolExecutionGroup
{
    /** @var array<int, ToolInterface> */
    private array $results = [];

    private readonly ToolCallCorrelation $correlation;

    private int $settled = 0;

    /** @var list<ToolInterface> */
    private array $unpresented = [];

    private ?ToolInterface $started = null;

    private int $announced = 0;

    private readonly ?ParallelToolFailures $parallelFailures;

    public function __construct(private readonly ToolCallMessage $message, ?ParallelToolNode $parallelNode = null)
    {
        $this->correlation = new ToolCallCorrelation();

        foreach ($message->getTools() as $position => $tool) {
            $this->correlation->registerCall($tool, $position);
        }

        $this->parallelFailures = $parallelNode !== null
            && extension_loaded('pcntl') && class_exists(Fork::class) && count($message->getTools()) > 1
            ? new ParallelToolFailures($parallelNode) : null;
    }

    public function belongsTo(ToolCallMessage $message): bool
    {
        return $message === $this->message;
    }

    public function start(ToolInterface $tool): void
    {
        ++$this->announced;

        if ($this->parallelFailures !== null) {
            return;
        }

        $this->started = $tool;
    }

    public function isSettling(): bool
    {
        return $this->parallelFailures !== null
            && $this->announced === count($this->message->getTools())
            && $this->settled < count($this->message->getTools());
    }

    public function throwIfFailed(): void
    {
        if (!$this->isSettling()) {
            $this->parallelFailures?->throwIfFailed();
        }
    }

    public function restore(): void
    {
        $this->parallelFailures?->restore();
    }

    public function record(ToolInterface $tool): ToolInterface
    {
        $tool = $this->parallelFailures?->result($tool) ?? $tool;
        $position = $this->correlation->matchResult($tool);

        if ($position !== null) {
            $this->results[$position] = $tool;
        }

        ++$this->settled;
        $this->started = null;

        return $tool;
    }

    public function fail(Throwable $failure): bool
    {
        if ($this->started === null) {
            return false;
        }

        $result = ToolOutcome::failed($this->started, $failure);
        $this->record($result);
        $this->unpresented[] = $result;

        return true;
    }

    /** @return list<ToolInterface> Results that still need to be shown. */
    public function reconcile(ChatHistoryInterface $history): array
    {
        $this->message->setStopReason('interrupted');
        $afterCall = false;

        foreach ($history->getMessages() as $message) {
            if ($message === $this->message) {
                $afterCall = true;
            } elseif ($afterCall && $message instanceof ToolResultMessage) {
                return [];
            }
        }

        $results = [];

        foreach ($this->message->getTools() as $position => $tool) {
            $result = $this->results[$position] ?? null;

            if ($result === null) {
                $result = ToolOutcome::notExecuted($tool);
                $this->unpresented[] = $result;
            }

            $results[] = $result;
        }

        // The call metadata survives reload even where Neuron omits result
        // message metadata. Adding the complete result persists both together.
        $history->addMessage(new ToolResultMessage($results));

        return $this->unpresented;
    }
}
