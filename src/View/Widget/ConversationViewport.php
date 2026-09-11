<?php

declare(strict_types=1);

namespace NeuronTui\View\Widget;

use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\ParentInterface;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

/**
 * A bounded window onto everything above the conversation controls.
 *
 * @internal
 */
final class ConversationViewport extends AbstractWidget implements ParentInterface, VerticallyExpandableInterface
{
    private const int SCROLL_LINES = 5;

    private int $scrollOffset = 0;

    private ?int $paintedContentHeight = null;

    private ?int $paintedViewportHeight = null;

    private ?int $paintedColumns = null;

    /** @var list<array{row: int, text: string}> */
    private array $paintedAnchors = [];

    public function __construct(private readonly ContainerWidget $content)
    {
    }

    /**
     * @return list<ContainerWidget>
     */
    public function all(): array
    {
        return [$this->content];
    }

    public function expandVertically(bool $expand): static
    {
        return $this;
    }

    public function isVerticallyExpanded(): bool
    {
        return true;
    }

    public function followLatest(): void
    {
        $this->invalidate();
    }

    public function reset(): void
    {
        $this->scrollOffset = 0;
        $this->paintedContentHeight = null;
        $this->paintedViewportHeight = null;
        $this->paintedColumns = null;
        $this->paintedAnchors = [];
        $this->invalidate();
    }

    public function scrollUp(): void
    {
        $this->setScrollOffset($this->scrollOffset + self::SCROLL_LINES);
    }

    public function scrollDown(): void
    {
        $this->setScrollOffset($this->scrollOffset - self::SCROLL_LINES);
    }

    /** @return string[] */
    public function render(RenderContext $context): array
    {
        $widgetContext = $this->getContext();

        if ($widgetContext === null) {
            return [];
        }

        $lines = $widgetContext->renderWidget($this->content, $context);
        $rows = $context->getRows();
        $contentHeight = count($lines);
        $resizedOffset = null;

        if (
            $this->scrollOffset > 0
            && $this->paintedColumns !== null
            && $this->paintedColumns !== $context->getColumns()
        ) {
            $resizedOffset = $this->offsetForPaintedAnchor($lines, $rows);
        }

        if (
            $this->scrollOffset > 0
            && $resizedOffset === null
            && $this->paintedContentHeight !== null
            && $this->paintedViewportHeight !== null
        ) {
            $this->scrollOffset += $contentHeight
                - $this->paintedContentHeight
                - ($rows - $this->paintedViewportHeight);
        }

        if ($resizedOffset !== null) {
            $this->scrollOffset = $resizedOffset;
        }

        $this->paintedContentHeight = $contentHeight;
        $this->paintedViewportHeight = $rows;
        $this->paintedColumns = $context->getColumns();
        $maximumOffset = max(0, $contentHeight - $rows);
        $this->scrollOffset = min(
            $maximumOffset,
            max(0, $this->scrollOffset),
        );

        $start = max(0, $contentHeight - $rows - $this->scrollOffset);
        $visibleLines = $maximumOffset === 0 ? $lines : array_slice(
            $lines,
            $start,
            $rows,
        );
        $this->rememberAnchors($visibleLines);

        return $visibleLines;
    }

    private function setScrollOffset(int $offset): void
    {
        $offset = max(0, $offset);

        if ($this->scrollOffset === $offset) {
            return;
        }

        $this->scrollOffset = $offset;
        $this->invalidate();
    }

    /**
     * Keeps a rendered line at the same physical row when changed terminal
     * width reflows content above the reading position.
     *
     * @param string[] $lines
     */
    private function offsetForPaintedAnchor(array $lines, int $rows): ?int
    {
        $positions = [];

        foreach ($lines as $index => $line) {
            $text = self::plainText($line);

            if ($text !== '') {
                $positions[$text][] = $index;
            }
        }

        foreach ($this->paintedAnchors as $anchor) {
            foreach ($positions[$anchor['text']] ?? [] as $position) {
                $start = $position - $anchor['row'];

                if ($start >= 0 && $start <= max(0, count($lines) - $rows)) {
                    return count($lines) - $rows - $start;
                }
            }
        }

        return null;
    }

    /** @param string[] $lines */
    private function rememberAnchors(array $lines): void
    {
        $this->paintedAnchors = [];

        foreach ($lines as $row => $line) {
            $text = self::plainText($line);

            if ($text !== '') {
                $this->paintedAnchors[] = [
                    'row' => $row,
                    'text' => $text,
                ];
            }
        }
    }

    private static function plainText(string $line): string
    {
        return rtrim(AnsiUtils::stripAnsiCodes($line));
    }
}
