<?php

declare(strict_types=1);

namespace NeuronTui\History;

use NeuronAI\Chat\History\HistoryTrimmer;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;

/** Keeps Neuron's trimming rules while allowing an unanswered interrupted User. @internal */
final class InterruptedHistoryTrimmer extends HistoryTrimmer
{
    public static function from(HistoryTrimmer $trimmer): self
    {
        return new self($trimmer->tokenCounter);
    }

    /** @param Message[] $messages */
    protected function validateAlternation(array $messages): void
    {
        $alternating = [];

        foreach ($messages as $index => $message) {
            $previous = $messages[$index - 1] ?? null;
            $next = $messages[$index + 1] ?? null;

            if ($message instanceof ToolResultMessage
                && $previous instanceof ToolCallMessage
                && $previous->stopReason() === 'interrupted'
                && $next instanceof UserMessage
                && !$next instanceof ToolResultMessage
            ) {
                continue;
            }

            $alternating[] = $message;
        }

        // The unanswered User remains in History and token accounting. Only
        // alternation validation skips it: the next User starts a new Turn.
        parent::validateAlternation(array_values(array_filter(
            $alternating,
            static fn (Message $message): bool => !(
                $message instanceof UserMessage
                && $message->getMetadata('stop_reason') === 'interrupted'
            ),
        )));
    }
}
