<?php

declare(strict_types=1);

namespace NeuronTui\History;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Tools\ToolCall;
use NeuronChatCore\Message\UserMessageFactory;
use NeuronChatCore\Message\UserMessageProcessorInterface;
use NeuronChatCore\Message\UserMessageProcessors;
use NeuronTui\View\HistoryEntryKind;
use NeuronTui\View\MessageTextFormatter;

use function array_values;
use function count;

/** Terminal presentation of native Neuron history. @internal */
final class HistoryProjection
{
    private readonly ToolCallCorrelation $correlation;

    /** @var array<int, ProjectedEntry> */
    private array $entries = [];

    /** @param array<Message> $messages */
    public function __construct(
        array $messages,
        private readonly UserMessageProcessorInterface $userMessageProcessor = new UserMessageProcessors(),
    ) {
        $this->correlation = new ToolCallCorrelation();
        foreach ($messages as $message) {
            $this->projectMessage($message);
        }
    }

    /** @return list<ProjectedEntry> */
    public function entries(): array
    {
        return array_values($this->entries);
    }

    private function projectMessage(Message $message): void
    {
        $role = MessageRole::tryFrom($message->getRole());
        if ($role !== MessageRole::USER && $role !== MessageRole::ASSISTANT) {
            return;
        }

        if ($message instanceof ToolResultMessage) {
            foreach ($message->getToolCalls() as $tool) {
                $position = $this->correlation->matchResult($tool) ?? $this->appendToolCall($tool);
                $this->entries[$position] = new ProjectedEntry(
                    HistoryEntryKind::ToolActivity,
                    ToolActivityText::completed($tool, 0.0),
                );
            }

            return;
        }

        if ($message instanceof ToolCallMessage) {
            $role = MessageRole::ASSISTANT;
        }

        $text = MessageTextFormatter::format($role === MessageRole::USER
            ? $this->userMessageProcessor->forDisplay(UserMessageFactory::fromMessage($message))
            : $message);
        if ($text !== '') {
            $this->entries[] = new ProjectedEntry(
                $role === MessageRole::USER
                    ? HistoryEntryKind::UserMessage
                    : HistoryEntryKind::AssistantMessage,
                $text,
            );
        }

        if ($message instanceof ToolCallMessage) {
            foreach ($message->getToolCalls() as $tool) {
                $this->appendToolCall($tool);
            }
        }
    }

    private function appendToolCall(ToolCall $tool): int
    {
        $position = count($this->entries);
        $this->entries[] = new ProjectedEntry(
            HistoryEntryKind::ToolActivity,
            ToolActivityText::pending($tool),
        );
        $this->correlation->registerCall($tool, $position);

        return $position;
    }
}
