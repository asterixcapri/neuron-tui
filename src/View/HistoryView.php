<?php

declare(strict_types=1);

namespace NeuronTui\View;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\Padding;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\ParentInterface;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Tui\Widget\Util\StringUtils;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

use function array_fill;
use function array_slice;
use function array_values;
use function count;
use function max;

/** @internal */
final class HistoryView extends AbstractWidget implements ParentInterface, VerticallyExpandableInterface
{
    private readonly ContainerWidget $messages;
    private readonly Renderer $renderer;
    private bool $expanded = true;
    private ?TextWidget $response = null;

    public function __construct()
    {
        $this->messages = (new ContainerWidget())->setStyle(new Style(gap: 1));
        $this->messages->setParent($this);
        $this->renderer = new Renderer();
    }

    /** @param iterable<Message> $history */
    public function load(iterable $history): void
    {
        $this->response = null;
        $this->messages->clear();
        foreach ($history as $message) {
            $content = $message->getContent();
            if ($content !== null && $content !== '') {
                if ($message->getRole() === 'user') {
                    $this->appendUser($content);
                } else {
                    $this->appendAgent($content);
                }
            }
            if ($message instanceof ToolCallMessage) {
                foreach ($message->getToolCalls() as $tool) {
                    $this->notify('Tool › ' . $tool->getName());
                }
            }
        }
    }

    private function appendUser(string $text): void
    {
        $this->append('You › ' . $text)->setStyle(new Style(
            padding: Padding::xy(1),
            background: '#292521',
            color: '#f0d2b5',
        ));
    }

    private function appendAgent(string $text): void
    {
        $this->append('Agent › ' . $text);
    }

    public function beginTurn(string $prompt): void
    {
        $this->appendUser($prompt);
        $this->response = $this->append('Agent › ');
    }

    public function appendResponse(string $text): void
    {
        $this->response ??= $this->append('Agent › ');
        $this->response->setText(StringUtils::stripControlBytes($this->response->getText() . $text));
    }

    public function notify(string $text): void
    {
        $this->finishTurn();
        $this->append($text);
    }

    public function finishTurn(): void
    {
        if ($this->response !== null && $this->response->getText() === 'Agent › ') {
            $this->messages->remove($this->response);
        }
        $this->response = null;
    }

    private function append(string $text): TextWidget
    {
        $widget = new TextWidget(StringUtils::stripControlBytes($text));
        $this->messages->add($widget);

        return $widget;
    }

    /** @return array{ContainerWidget} */
    public function all(): array
    {
        return [$this->messages];
    }

    public function expandVertically(bool $expand): static
    {
        $this->expanded = $expand;
        $this->invalidate();

        return $this;
    }

    public function isVerticallyExpanded(): bool
    {
        return $this->expanded;
    }

    /** @return list<string> */
    public function render(RenderContext $context): array
    {
        $rows = max(1, $context->getRows());
        $lines = $this->renderer->renderWidget($this->messages, $context);
        $visible = array_values(array_slice($lines, -$rows));

        return [...$visible, ...array_fill(0, max(0, $rows - count($visible)), '')];
    }
}
