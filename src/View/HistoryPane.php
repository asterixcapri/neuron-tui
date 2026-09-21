<?php

declare(strict_types=1);

namespace NeuronTui\View;

use Symfony\Component\Tui\Terminal\TerminalInterface;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\MarkdownWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * Owns the painted History: its entries, their heights and the reading
 * position.
 *
 * Everything that changes how tall the painted History is goes through this
 * module — adding an entry, writing to one through its handle, removing one,
 * clearing the pane — so the reading position is kept in step without any
 * caller being obliged to report a change.
 *
 * @internal
 */
final class HistoryPane
{
    private const int SCROLL_LINES = 5;

    private const int NOTE_RESERVED_COLUMNS = 2;

    private const int MESSAGE_RESERVED_COLUMNS = 3;

    private readonly ContainerWidget $widget;

    /** @var list<HistoryEntry> */
    private array $entries = [];

    private int $paintedHeight = 0;

    private int $scrollOffset = 0;

    public function __construct(
        private readonly Tui $tui,
        private readonly TerminalInterface $terminal,
    ) {
        $this->widget = new ContainerWidget();
        $this->widget->addStyleClass('history');
        $this->widget->expandVertically(true);
    }

    public function widget(): ContainerWidget
    {
        return $this->widget;
    }

    /**
     * Adds an entry using the presentation associated with its kind.
     */
    public function addEntry(HistoryEntryKind $kind, string $text): HistoryEntry
    {
        return match ($kind) {
            HistoryEntryKind::UserMessage => $this->addPrefixedEntry('❯', $text, 'user'),
            HistoryEntryKind::AssistantMessage => $this->addPrefixedEntry('●', $text, 'agent'),
            HistoryEntryKind::ToolActivity => $this->addNote($text, 'tool'),
            HistoryEntryKind::Notice => $this->addPrefixedEntry('·', $text, 'notice', 'event-muted'),
            HistoryEntryKind::Warning => $this->addPrefixedEntry('!', $text, 'warning'),
            HistoryEntryKind::Error => $this->addPrefixedEntry('×', $text, 'error'),
            HistoryEntryKind::ResponseStopped => $this->addPrefixedEntry('■', $text, 'notice', 'event-muted'),
            HistoryEntryKind::WorkingIndicator => $this->addNote($text, 'loading'),
        };
    }

    private function addPrefixedEntry(
        string $symbol,
        string $text,
        string $styleClass,
        ?string $contentStyleClass = null,
    ): HistoryEntry {
        $message = new ContainerWidget();
        $message->addStyleClass('message');

        if ($styleClass === 'user') {
            $message->addStyleClass('user-message');
        }

        $label = new TextWidget($symbol);
        $label->addStyleClass('speaker');
        $label->addStyleClass($styleClass);
        $markdown = new MarkdownWidget($text);
        $markdown->addStyleClass('message-content');

        if ($contentStyleClass !== null) {
            $markdown->addStyleClass($contentStyleClass);
        }

        $message->add($label);
        $message->add($markdown);

        $entry = new HistoryEntry(
            $this->terminal,
            $message,
            $markdown,
            self::MESSAGE_RESERVED_COLUMNS + mb_strwidth($symbol, 'UTF-8'),
            $this->paintedHeightChanged(...),
        );

        return $this->add($entry);
    }

    /**
     * Adds an unspoken line of the History, such as tool activity.
     */
    private function addNote(string $text, string $styleClass): HistoryEntry
    {
        $note = new TextWidget($text);
        $note->addStyleClass($styleClass);

        $entry = new HistoryEntry(
            $this->terminal,
            $note,
            $note,
            self::NOTE_RESERVED_COLUMNS,
            $this->paintedHeightChanged(...),
        );

        return $this->add($entry);
    }

    public function remove(HistoryEntry $entry): void
    {
        $remaining = array_values(array_filter(
            $this->entries,
            static fn (HistoryEntry $painted): bool => $painted !== $entry,
        ));

        if (count($remaining) === count($this->entries)) {
            return;
        }

        $this->entries = $remaining;
        $this->widget->remove($entry->widget());
        $this->paintedHeightChanged();
    }

    /**
     * Empties the pane, leaving it ready to paint a different History.
     */
    public function clear(): void
    {
        $this->entries = [];
        $this->widget->clear();
        $this->paintedHeight = 0;
        $this->setScrollOffset(0);
    }

    /**
     * Keeps the newest entry in view unless the reader has scrolled away.
     */
    public function followLatest(): void
    {
        if ($this->scrollOffset !== 0) {
            $this->tui->requestRender();

            return;
        }

        $this->setScrollOffset(0);
    }

    public function scrollUp(): void
    {
        $this->setScrollOffset($this->scrollOffset + self::SCROLL_LINES);
    }

    public function scrollDown(): void
    {
        $this->setScrollOffset($this->scrollOffset - self::SCROLL_LINES);
    }

    private function add(HistoryEntry $entry): HistoryEntry
    {
        $this->entries[] = $entry;
        $this->widget->add($entry->widget());
        $this->paintedHeightChanged();

        return $entry;
    }

    /**
     * Moves the reading position by however much the painted History grew or
     * shrank, so that what is being read stays where it is.
     */
    private function paintedHeightChanged(): void
    {
        $previousHeight = $this->paintedHeight;
        $this->paintedHeight = $this->measure();
        $difference = $this->paintedHeight - $previousHeight;

        if ($this->scrollOffset > 0 && $difference !== 0) {
            $this->setScrollOffset($this->scrollOffset + $difference);

            return;
        }

        $this->tui->requestRender();
    }

    private function measure(): int
    {
        $height = 0;

        foreach ($this->entries as $entry) {
            $height += $entry->height();
        }

        return $height + max(0, count($this->entries) - 1);
    }

    /**
     * Moves the reading position that many lines above the newest entry.
     */
    private function setScrollOffset(int $offset): void
    {
        $this->scrollOffset = max(0, $offset);
        $this->tui->setScrollOffset($this->scrollOffset);
        $this->tui->requestRender();
    }
}
