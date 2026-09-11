<?php

declare(strict_types=1);

namespace NeuronTui\History;

use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\HistoryTrimmer;

/** Adapts Neuron's default validator without replacing the Host's History. @internal */
final class InterruptionHistory extends AbstractChatHistory
{
    public static function prepare(ChatHistoryInterface $history): void
    {
        // Same-hierarchy protected access preserves the original Session,
        // persistence hooks, context window and token counter. A Host's own
        // trimmer remains responsible for its custom validation contract.
        if ($history instanceof AbstractChatHistory && $history->trimmer::class === HistoryTrimmer::class) {
            $history->trimmer = InterruptedHistoryTrimmer::from($history->trimmer);
        }
    }
}
