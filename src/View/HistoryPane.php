<?php

declare(strict_types=1);

namespace NeuronTui\View;

use NeuronTui\View\Widget\ConversationViewport;
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
    private readonly ContainerWidget $widget;

    /** @var list<HistoryEntry> */
    private array $entries = [];

    public function __construct(
        private readonly Tui $tui,
        private readonly ?ConversationViewport $viewport = null,
    ) {
        $this->widget = new ContainerWidget();
        $this->widget->addStyleClass('history');
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
        string $text,
        string $styleClass,
    ): HistoryEntry {
        $message = new ContainerWidget();
        $message->addStyleClass('message');

        if ($styleClass === 'user') {
            $message->addStyleClass('user-message');
        }

        $label = new TextWidget($speaker);
        $label->addStyleClass('speaker');
        $label->addStyleClass($styleClass);
        $markdown = new MarkdownWidget($text);
        $markdown->addStyleClass('message-content');
        $message->add($label);
        $message->add($markdown);

        $entry = new HistoryEntry(
            $message,
            $markdown,
            $this->historyChanged(...),
        );

        return $this->add($entry);
    }

    /**
     * Adds an unspoken line of the History, such as tool activity.
     */
    public function addNote(string $text, string $styleClass): HistoryEntry
    {
        $note = new TextWidget($text);
        $note->addStyleClass($styleClass);

        $entry = new HistoryEntry(
            $note,
            $note,
            $this->historyChanged(...),
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
        $this->historyChanged();
    }

    /**
     * Empties the pane, leaving it ready to paint a different History.
     */
    public function clear(): void
    {
        $this->entries = [];
        $this->widget->clear();
        $this->viewport?->reset();
        $this->tui->requestRender();
    }

    /**
     * Keeps the newest entry in view unless the reader has scrolled away.
     */
    public function followLatest(): void
    {
        $this->viewport?->followLatest();
        $this->tui->requestRender();
    }

    public function scrollUp(): void
    {
        $this->viewport?->scrollUp();
        $this->tui->requestRender();
    }

    public function scrollDown(): void
    {
        $this->viewport?->scrollDown();
        $this->tui->requestRender();
    }

    private function add(HistoryEntry $entry): HistoryEntry
    {
        $this->entries[] = $entry;
        $this->widget->add($entry->widget());
        $this->historyChanged();

        return $entry;
    }

    /**
     * Invalidates the viewport whenever painted History changes.
     *
     * The viewport measures the complete rendered upper region and owns the
     * reading position. History entries deliberately do not estimate that
     * movement: their measurements do not include the header or queue and
     * become stale when terminal width changes.
     */
    private function historyChanged(): void
    {
        $this->tui->requestRender();
    }
}
