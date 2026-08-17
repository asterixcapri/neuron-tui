<?php

declare(strict_types=1);

namespace NeuronCli\Tui;

use Closure;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Terminal\TerminalInterface;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\MarkdownWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * The handle the History pane hands back for an entry that keeps changing.
 *
 * Writing through the handle is the only way an entry changes after it has
 * been painted. The pane is told on every write, so nobody outside the pane
 * ever has to work out how much taller or shorter the entry became.
 *
 * @internal
 */
final class HistoryEntry
{
    private int $height = 1;

    /**
     * @param int $reservedColumns terminal columns the entry cannot paint in
     * @param Closure(): void $changed
     */
    public function __construct(
        private readonly TerminalInterface $terminal,
        private readonly AbstractWidget $widget,
        private readonly MarkdownWidget|TextWidget $contents,
        private readonly int $reservedColumns,
        private readonly Closure $changed,
    ) {
        $this->measure();
    }

    public function setText(string $text): void
    {
        $this->contents->setText($text);
        $this->measure();
        ($this->changed)();
    }

    public function appendText(string $chunk): void
    {
        $this->setText($this->contents->getText() . $chunk);
    }

    /**
     * Only the History pane paints or measures an entry.
     */
    public function widget(): AbstractWidget
    {
        return $this->widget;
    }

    public function height(): int
    {
        return $this->height;
    }

    private function measure(): void
    {
        $columns = max(
            1,
            $this->terminal->getColumns() - $this->reservedColumns,
        );

        $this->height = max(1, count($this->contents->render(
            new RenderContext($columns, PHP_INT_MAX),
        )));
    }
}
