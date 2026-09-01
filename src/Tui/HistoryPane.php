<?php

declare(strict_types=1);

namespace NeuronTui\Tui;

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
     * Adds a message spoken by somebody, rendered as Markdown.
     */
    public function addMessage(
        string $speaker,
        string $contents,
        string $style,
    ): HistoryEntry {
        $message = new ContainerWidget();
        $message->addStyleClass('message');

        if ($style === 'user') {
            $message->addStyleClass('user-message');
        }

        $label = new TextWidget($speaker);
        $label->addStyleClass('speaker');
        $label->addStyleClass($style);
        $markdown = new MarkdownWidget($contents);
        $markdown->addStyleClass('message-content');
        $message->add($label);
        $message->add($markdown);

        return $this->add(new HistoryEntry(
            $this->terminal,
            $message,
            $markdown,
            self::MESSAGE_RESERVED_COLUMNS + mb_strwidth($speaker, 'UTF-8'),
            $this->paintedHeightChanged(...),
        ));
    }

    /**
     * Adds an unspoken line of the History, such as tool activity.
     */
    public function addNote(string $text, string $style): HistoryEntry
    {
        $note = new TextWidget($text);
        $note->addStyleClass($style);

        return $this->add(new HistoryEntry(
            $this->terminal,
            $note,
            $note,
            self::NOTE_RESERVED_COLUMNS,
            $this->paintedHeightChanged(...),
        ));
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
        $this->readAt(0);
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

        $this->readAt(0);
    }

    public function scrollUp(): void
    {
        $this->readAt($this->scrollOffset + self::SCROLL_LINES);
    }

    public function scrollDown(): void
    {
        $this->readAt($this->scrollOffset - self::SCROLL_LINES);
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
            $this->readAt($this->scrollOffset + $difference);

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
    private function readAt(int $offset): void
    {
        $this->scrollOffset = max(0, $offset);
        $this->tui->setScrollOffset($this->scrollOffset);
        $this->tui->requestRender();
    }
}
