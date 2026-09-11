<?php

declare(strict_types=1);

namespace NeuronTui\View\Widget;

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
    private int $scrollOffset = 0;

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

    public function setScrollOffset(int $offset): void
    {
        $offset = max(0, $offset);

        if ($this->scrollOffset === $offset) {
            return;
        }

        $this->scrollOffset = $offset;
        $this->invalidate();
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

        if (count($lines) <= $rows) {
            return $lines;
        }

        $maximumOffset = count($lines) - $rows;
        $offset = min($this->scrollOffset, $maximumOffset);

        return array_slice(
            $lines,
            count($lines) - $rows - $offset,
            $rows,
        );
    }
}
