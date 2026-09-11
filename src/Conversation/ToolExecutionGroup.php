<?php

declare(strict_types=1);

namespace NeuronTui\Conversation;

use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Tools\ToolInterface;
use NeuronTui\History\ToolOutcome;
use Throwable;

/** Tracks actual outcomes separately from Neuron's tool announcements. @internal */
final class ToolExecutionGroup
{
    /** @var array<string, ToolInterface> */
    private array $results = [];

    /** @var list<ToolInterface> */
    private array $unpresented = [];

    private ?ToolInterface $started = null;

    public function __construct(private readonly ToolCallMessage $message)
    {
    }

    public function belongsTo(ToolCallMessage $message): bool
    {
        return $message === $this->message;
    }

    public function start(ToolInterface $tool): void
    {
        $this->started = $tool;
    }

    public function record(ToolInterface $tool): void
    {
        $this->results[self::key($tool)] = $tool;
        $this->started = null;
    }

    public function fail(Throwable $failure): bool
    {
        if ($this->started === null) {
            return false;
        }

        $result = ToolOutcome::failed($this->started, $failure);
        $this->results[self::key($this->started)] = $result;
        $this->started = null;
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

        foreach ($this->message->getTools() as $tool) {
            $result = $this->results[self::key($tool)] ?? null;

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

    private static function key(ToolInterface $tool): string
    {
        return $tool->getCallId() ?? 'object:' . spl_object_id($tool);
    }
}
