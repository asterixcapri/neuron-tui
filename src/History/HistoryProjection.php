<?php

declare(strict_types=1);

namespace NeuronTui\History;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ContentBlocks\VideoContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Tools\ToolInterface;
use NeuronTui\View\DisplayableText;
use NeuronTui\View\UserMessageProjection;

/**
 * The Agent's messages as the one ordered stream of entries a person sees.
 *
 * Defines historical presentation rules: which messages are excluded, what
 * replaces attachment payloads, and where a tool's result belongs relative
 * to its call. A result may arrive out of order, or never arrive at all.
 * The Agent owns the History; Session metadata is independent of this
 * terminal projection, and View owns the mutable widgets and rendering.
 *
 * The projection is a snapshot that can be built at any moment: opening the
 * TUI, starting a new Session, or returning to one.
 *
 * @internal
 */
final class HistoryProjection
{
    private const int FILENAME_WIDTH = 80;

    /**
     * Presentation fallback for unavailable historical timing, rather than
     * a measured duration.
     */
    private const float FALLBACK_DURATION_SECONDS = 0.0;

    private readonly ToolCallCorrelation $correlation;

    /**
     * Entries in the order a person reads them, each one still reachable by
     * its position so that a result can complete the call it belongs to.
     *
     * @var array<int, ProjectedEntry>
     */
    private array $entries = [];

    /** @param array<Message> $messages */
    public function __construct(array $messages)
    {
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
        $role = $message->getRole();

        if (
            $role !== MessageRole::USER->value
            && $role !== MessageRole::ASSISTANT->value
        ) {
            return;
        }

        if ($message instanceof ToolCallMessage) {
            $this->appendMessage(ProjectedEntryKind::Agent, $message);

            foreach ($message->getTools() as $tool) {
                $this->appendToolCall($tool);
            }

            return;
        }

        if ($message instanceof ToolResultMessage) {
            foreach ($message->getTools() as $tool) {
                $this->applyToolResult($tool);
            }

            return;
        }

        $this->appendMessage(
            $role === MessageRole::USER->value
                ? ProjectedEntryKind::Person
                : ProjectedEntryKind::Agent,
            $message,
        );
    }

    private function appendMessage(ProjectedEntryKind $kind, Message $message): void
    {
        $text = $this->messageText($message);

        if ($text === '') {
            return;
        }

        if (
            $kind === ProjectedEntryKind::Person
            && $this->containsOnlyText($message)
        ) {
            $text = UserMessageProjection::project($text);
        }

        $this->entries[] = new ProjectedEntry($kind, $text);
    }

    private function containsOnlyText(Message $message): bool
    {
        $blocks = $message->getContentBlocks();

        return count($blocks) === 1 && $blocks[0] instanceof TextContent;
    }

    private function appendToolCall(ToolInterface $tool): int
    {
        $this->entries[] = new ProjectedEntry(
            ProjectedEntryKind::Tool,
            ToolActivityText::pending($tool),
        );
        $position = count($this->entries) - 1;
        $this->correlation->registerCall($tool, $position);

        return $position;
    }

    private function applyToolResult(ToolInterface $tool): void
    {
        // A result that finds no call of its own is still worth showing, so
        // it opens the call it should have answered and closes it at once.
        $position = $this->correlation->matchResult($tool)
            ?? $this->appendToolCall($tool);

        $this->entries[$position] = new ProjectedEntry(
            ProjectedEntryKind::Tool,
            ToolActivityText::completed($tool, self::FALLBACK_DURATION_SECONDS),
        );
    }

    private function messageText(Message $message): string
    {
        $parts = [];

        foreach ($message->getContentBlocks() as $block) {
            $content = match (true) {
                $block instanceof ReasoningContent => null,
                $block instanceof TextContent => $block->getContent(),
                $block instanceof ImageContent => '[Image]',
                $block instanceof FileContent => $this->filePlaceholder($block),
                $block instanceof AudioContent => '[Audio]',
                $block instanceof VideoContent => '[Video]',
                default => null,
            };

            if ($content !== null && $content !== '') {
                $parts[] = $content;
            }
        }

        return implode("\n\n", $parts);
    }

    private function filePlaceholder(FileContent $file): string
    {
        if ($file->filename === null) {
            return '[File]';
        }

        // Safe first, so a stripped escape sequence cannot forge the
        // separator that basename() then splits on.
        $filename = DisplayableText::safe($file->filename);
        $filename = basename(str_replace('\\', '/', $filename));
        $filename = DisplayableText::preview($filename, self::FILENAME_WIDTH);

        return $filename === '' ? '[File]' : '[File: ' . $filename . ']';
    }
}
