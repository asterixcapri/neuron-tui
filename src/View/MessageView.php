<?php

declare(strict_types=1);

namespace NeuronTui\View;

use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\MarkdownWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Tui\Widget\Util\StringUtils;

use function max;
use function str_repeat;

/** @internal */
final class MessageView extends TextWidget
{
    private readonly ?MarkdownWidget $markdown;

    public function __construct(string $text, private readonly MessageKind $kind)
    {
        parent::__construct(StringUtils::stripControlBytes($text));
        $this->markdown = $kind === MessageKind::Agent ? new MarkdownWidget($this->getText()) : null;
        $this->setStyle(match ($kind) {
            MessageKind::User => new Style(background: '#383838', color: '#eeeeee'),
            MessageKind::Agent => new Style(color: '#eeeeee'),
            MessageKind::ToolCall => new Style(color: '#d99a70'),
            MessageKind::ToolResult => new Style(color: '#91b99a'),
            MessageKind::System => new Style(color: '#999999', dim: true),
            MessageKind::Notice => new Style(color: '#9caee0'),
            MessageKind::Error => new Style(color: '#e88b8b'),
        });
    }

    public function setText(string $text): static
    {
        parent::setText(StringUtils::stripControlBytes($text));
        $this->markdown?->setText($this->getText());

        return $this;
    }

    /** @return list<string> */
    public function render(RenderContext $context): array
    {
        $prefix = match ($this->kind) {
            MessageKind::User => '❯ ',
            MessageKind::Agent => '● ',
            MessageKind::ToolCall => '◆ ',
            MessageKind::ToolResult => '└ ',
            MessageKind::System => '· ',
            MessageKind::Notice => 'ℹ ',
            MessageKind::Error => '! ',
        };
        $width = AnsiUtils::visibleWidth($prefix);
        if ($context->getColumns() <= $width) {
            return [AnsiUtils::truncateToWidth($prefix, max(1, $context->getColumns()), '')];
        }
        $contentContext = $context->withColumns($context->getColumns() - $width);
        $lines = $this->markdown !== null ? $this->markdown->render($contentContext) : parent::render($contentContext);
        $rendered = [];
        foreach ($lines as $index => $line) {
            $rendered[] = ($index === 0 ? $prefix : str_repeat(' ', $width)) . $line;
        }

        return $rendered;
    }
}
