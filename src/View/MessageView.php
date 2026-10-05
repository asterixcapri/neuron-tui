<?php

declare(strict_types=1);

namespace NeuronTui\View;

use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\MarkdownWidget;
use Symfony\Component\Tui\Widget\ParentInterface;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Tui\Widget\Util\StringUtils;
use Tempest\Highlight\Highlighter;

use function max;
use function str_repeat;

/** @internal */
final class MessageView extends TextWidget implements ParentInterface
{
    private readonly ?MarkdownWidget $markdown;

    public function __construct(string $text, private readonly MessageKind $kind)
    {
        parent::__construct(StringUtils::stripControlBytes($text));
        $this->markdown = $kind === MessageKind::Agent
            ? new MarkdownWidget($this->getText(), highlighter: new Highlighter(new Theme()))
            : null;
        $this->setStyle(match ($kind) {
            MessageKind::User => new Style(background: Theme::USER_BACKGROUND, color: Theme::TEXT),
            MessageKind::Agent => new Style(color: Theme::TEXT),
            MessageKind::ToolCall => new Style(color: Theme::ACCENT),
            MessageKind::ToolResult => new Style(color: Theme::SUCCESS),
            MessageKind::System => new Style(color: Theme::MUTED, dim: true),
            MessageKind::Notice => new Style(color: Theme::INFO),
            MessageKind::Error => new Style(color: Theme::ERROR),
        });
    }

    /** @return list<MarkdownWidget> */
    public function all(): array
    {
        return $this->markdown === null ? [] : [$this->markdown];
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
