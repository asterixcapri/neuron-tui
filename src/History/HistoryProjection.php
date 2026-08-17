<?php

declare(strict_types=1);

namespace NeuronCli\History;

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
use NeuronCli\Tui\DisplayableText;

/**
 * The Agent's messages as the one ordered stream of entries a person sees.
 *
 * Every rule about what is shown of a conversation is here: which messages
 * never reach a person, what a payload is replaced with, and where a tool's
 * result belongs relative to the call that asked for it. Pairing a call with
 * its result is the History's business, not something callers arrange between
 * themselves — a result may arrive out of order, or never arrive at all.
 *
 * The projection reads the messages and keeps nothing, so it can be asked for
 * the stream at any moment: opening the TUI, starting a new Session, or
 * returning to one.
 *
 * @internal
 */
final class HistoryProjection
{
    private const int FILENAME_WIDTH = 80;

    /**
     * Stored messages carry no timings, so a call and the result already
     * filed beside it are read in the same instant.
     */
    private const float NOTHING_WAS_WAITED_FOR = 0.0;

    private readonly ToolCorrelation $correlation;

    /**
     * Entries in the order a person reads them, each one still reachable by
     * its position so that a result can complete the call it belongs to.
     *
     * @var array<int, Entry>
     */
    private array $entries = [];

    private function __construct()
    {
        $this->correlation = new ToolCorrelation();
    }

    /**
     * @param array<Message> $messages
     *
     * @return list<Entry>
     */
    public static function entriesFor(array $messages): array
    {
        $projection = new self();

        foreach ($messages as $message) {
            $projection->read($message);
        }

        return array_values($projection->entries);
    }

    private function read(Message $message): void
    {
        $role = $message->getRole();

        if (
            $role !== MessageRole::USER->value
            && $role !== MessageRole::ASSISTANT->value
        ) {
            return;
        }

        if ($message instanceof ToolCallMessage) {
            $this->say(EntryKind::Agent, $message);

            foreach ($message->getTools() as $tool) {
                $this->callTool($tool);
            }

            return;
        }

        if ($message instanceof ToolResultMessage) {
            foreach ($message->getTools() as $tool) {
                $this->completeTool($tool);
            }

            return;
        }

        $this->say(
            $role === MessageRole::USER->value
                ? EntryKind::Person
                : EntryKind::Agent,
            $message,
        );
    }

    private function say(EntryKind $kind, Message $message): void
    {
        $contents = self::contents($message);

        if ($contents === '') {
            return;
        }

        $this->entries[] = new Entry($kind, $contents);
    }

    private function callTool(ToolInterface $tool): int
    {
        $this->entries[] = new Entry(
            EntryKind::Tool,
            ToolActivityText::pending($tool),
        );
        $position = count($this->entries) - 1;
        $this->correlation->called($tool, $position);

        return $position;
    }

    private function completeTool(ToolInterface $tool): void
    {
        // A result that finds no call of its own is still worth showing, so
        // it opens the call it should have answered and closes it at once.
        $position = $this->correlation->calledAt($tool)
            ?? $this->callTool($tool);

        $this->entries[$position] = new Entry(
            EntryKind::Tool,
            ToolActivityText::completed($tool, self::NOTHING_WAS_WAITED_FOR),
        );
    }

    private static function contents(Message $message): string
    {
        $contents = [];

        foreach ($message->getContentBlocks() as $block) {
            $content = match (true) {
                $block instanceof ReasoningContent => null,
                $block instanceof TextContent => $block->getContent(),
                $block instanceof ImageContent => '[Image]',
                $block instanceof FileContent => self::filePlaceholder($block),
                $block instanceof AudioContent => '[Audio]',
                $block instanceof VideoContent => '[Video]',
                default => null,
            };

            if ($content !== null && $content !== '') {
                $contents[] = $content;
            }
        }

        return implode("\n\n", $contents);
    }

    private static function filePlaceholder(FileContent $file): string
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
