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

    private ?string $paintedText = null;

    private ?int $paintedAnchorOffset = null;

    private ?int $paintedAnchorRow = null;

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
        $this->paintedText = null;
        $this->paintedAnchorOffset = null;
        $this->paintedAnchorRow = null;
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
        $this->rememberAnchor($lines, $start, $rows);

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
     * Keeps the same place in the content at the same physical row when a
     * changed terminal width reflows the rendered lines.
     *
     * @param string[] $lines
     */
    private function offsetForPaintedAnchor(array $lines, int $rows): ?int
    {
        if (
            $this->paintedText === null
            || $this->paintedAnchorOffset === null
            || $this->paintedAnchorRow === null
        ) {
            return null;
        }

        $lineLengths = array_values(array_map(
            self::normalizedLength(...),
            $lines,
        ));
        $text = implode('', array_map(self::normalizedText(...), $lines));
        $anchorOffset = $this->findAnchorOffset($text);

        if ($anchorOffset === null) {
            return null;
        }

        $position = self::lineAtOffset($lineLengths, $anchorOffset);
        $start = $position - $this->paintedAnchorRow;

        if ($start < 0 || $start > max(0, count($lines) - $rows)) {
            return null;
        }

        return count($lines) - $rows - $start;
    }

    /**
     * Finds the previous anchor in reflowed text. Increasing the amount of
     * surrounding content makes repeated rendered rows unambiguous while the
     * whitespace-free comparison survives complete line reflow.
     */
    private function findAnchorOffset(string $text): ?int
    {
        $paintedLength = strlen($this->paintedText ?? '');

        foreach ([32, 64, 128, 256, 512, $paintedLength] as $contextLength) {
            $start = max(0, $this->paintedAnchorOffset - intdiv($contextLength, 2));
            $start = min($start, max(0, $paintedLength - $contextLength));
            $needle = substr($this->paintedText ?? '', $start, $contextLength);

            if ($needle === '' || self::occurrences($this->paintedText ?? '', $needle) !== 1) {
                continue;
            }

            $position = strpos($text, $needle);

            if ($position !== false && strpos($text, $needle, $position + 1) === false) {
                return $position + $this->paintedAnchorOffset - $start;
            }
        }

        return null;
    }

    /** @param string[] $lines */
    private function rememberAnchor(array $lines, int $start, int $rows): void
    {
        $normalized = array_map(self::normalizedText(...), $lines);
        $this->paintedText = implode('', $normalized);
        $this->paintedAnchorOffset = null;
        $this->paintedAnchorRow = null;
        $offset = array_sum(array_map('strlen', array_slice($normalized, 0, $start)));

        foreach (array_slice($normalized, $start, $rows) as $row => $text) {
            if ($text !== '') {
                $this->paintedAnchorOffset = $offset;
                $this->paintedAnchorRow = $row;

                return;
            }

            $offset += strlen($text);
        }
    }

    private static function normalizedText(string $line): string
    {
        return (string) preg_replace(
            '/\s+/u',
            '',
            AnsiUtils::stripAnsiCodes($line),
        );
    }

    private static function normalizedLength(string $line): int
    {
        return strlen(self::normalizedText($line));
    }

    /** @param list<int> $lineLengths */
    private static function lineAtOffset(array $lineLengths, int $offset): int
    {
        $position = 0;

        foreach ($lineLengths as $line => $length) {
            if ($length > 0 && $offset < $position + $length) {
                return $line;
            }

            $position += $length;
        }

        return max(0, count($lineLengths) - 1);
    }

    private static function occurrences(string $haystack, string $needle): int
    {
        $count = 0;
        $offset = 0;

        while (($position = strpos($haystack, $needle, $offset)) !== false) {
            ++$count;
            $offset = $position + 1;
        }

        return $count;
    }
}
