<?php

declare(strict_types=1);

namespace NeuronTui\View;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\ParentInterface;
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
    private ?MessageView $response = null;

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
                $kind = match ($message->getRole()) {
                    'user' => MessageKind::User,
                    'assistant' => MessageKind::Agent,
                    default => MessageKind::System,
                };
                $this->append($content, $kind);
            }
            if ($message instanceof ToolCallMessage) {
                foreach ($message->getToolCalls() as $tool) {
                    $this->notify($tool->getName() . ' …', MessageKind::ToolCall);
                }
            }
            if ($message instanceof ToolResultMessage) {
                foreach ($message->getToolCalls() as $tool) {
                    $this->notify($tool->getName() . ' completed', MessageKind::ToolResult);
                }
            }
        }
    }

    public function beginTurn(string $prompt): void
    {
        $this->append($prompt, MessageKind::User);
        $this->response = $this->append('', MessageKind::Agent);
    }

    public function appendResponse(string $text): void
    {
        $this->response ??= $this->append('', MessageKind::Agent);
        $this->response->setText($this->response->getText() . $text);
    }

    public function notify(string $text, MessageKind $kind = MessageKind::Notice): void
    {
        $this->finishTurn();
        $this->append($text, $kind);
    }

    public function finishTurn(): void
    {
        if ($this->response !== null && $this->response->getText() === '') {
            $this->messages->remove($this->response);
        }
        $this->response = null;
    }

    private function append(string $text, MessageKind $kind): MessageView
    {
        $widget = new MessageView($text, $kind);
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
