<?php

declare(strict_types=1);

namespace NeuronTui\History;

use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\HistoryTrimmer;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use ReflectionMethod;

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

    public static function replaceCompletedResponse(ChatHistoryInterface $history, ?AssistantMessage $response): bool
    {
        if (!$history instanceof AbstractChatHistory) {
            return false;
        }

        // Some histories (including Neuron's Eloquent history) persist only
        // incremental hooks and inherit a no-op setMessages. They must use
        // the public fallback; a snapshot would silently leave storage stale.
        if ($history::class !== InMemoryChatHistory::class
            && (new ReflectionMethod($history, 'setMessages'))->getDeclaringClass()->getName() === AbstractChatHistory::class
        ) {
            return false;
        }

        // Replace the response in one retained snapshot. Replaying every old
        // message would clear persistent storage and repeat onNewMessage hooks.
        array_pop($history->history);

        if ($response !== null) {
            $history->history[] = $response;
        }

        $history->trimHistory();
        $history->setMessages($history->history);

        return true;
    }
}
