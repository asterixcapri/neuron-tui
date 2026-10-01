<?php

declare(strict_types=1);

namespace NeuronTui\History;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message;
use NeuronChatCore\History\HistoryProjection as NeutralHistoryProjection;
use NeuronChatCore\History\MessageEntry;
use NeuronChatCore\History\ToolEntry;
use NeuronChatCore\Message\UserMessageProcessorInterface;
use NeuronChatCore\Message\UserMessageProcessors;
use NeuronTui\View\HistoryEntryKind;
use NeuronTui\View\MessageTextFormatter;

/** Terminal formatting of the core's neutral History snapshot. @internal */
final class HistoryProjection
{
    /** @var list<ProjectedEntry> */
    private array $entries = [];

    /** @param array<Message> $messages */
    public function __construct(
        array $messages,
        UserMessageProcessorInterface $userMessageProcessor = new UserMessageProcessors(),
    ) {
        $projection = new NeutralHistoryProjection($messages, $userMessageProcessor);
        foreach ($projection->entries() as $entry) {
            if ($entry instanceof MessageEntry) {
                $text = MessageTextFormatter::format($entry->message);
                if ($text !== '') {
                    $this->entries[] = new ProjectedEntry(
                        $entry->role === MessageRole::USER
                            ? HistoryEntryKind::UserMessage
                            : HistoryEntryKind::AssistantMessage,
                        $text,
                    );
                }
            } elseif ($entry instanceof ToolEntry) {
                $this->entries[] = new ProjectedEntry(
                    HistoryEntryKind::ToolActivity,
                    $entry->completed
                        ? ToolActivityText::completed($entry->tool, 0.0)
                        : ToolActivityText::pending($entry->tool),
                );
            }
        }
    }

    /** @return list<ProjectedEntry> */
    public function entries(): array
    {
        return $this->entries;
    }
}
