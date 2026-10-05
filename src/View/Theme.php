<?php

declare(strict_types=1);

namespace NeuronTui\View;

use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Widget\MarkdownWidget;
use Tempest\Highlight\TerminalTheme;
use Tempest\Highlight\Themes\EscapesTerminalTheme;
use Tempest\Highlight\Tokens\TokenType;
use Tempest\Highlight\Tokens\TokenTypeEnum;

/** @internal */
final class Theme implements TerminalTheme
{
    use EscapesTerminalTheme;

    public const string TEXT = '#eeeeee';
    public const string SECONDARY = '#bbbbbb';
    public const string MUTED = '#999999';
    public const string BORDER = '#888888';
    public const string PROMPT = '#ffffff';
    public const string USER_BACKGROUND = '#383838';
    public const string ACCENT = '#d99a70';
    public const string SUCCESS = '#91b99a';
    public const string INFO = '#9caee0';
    public const string ERROR = '#e88b8b';

    public static function styleSheet(): StyleSheet
    {
        return new StyleSheet([
            MarkdownWidget::class . '::heading' => new Style(color: self::ACCENT, bold: true),
            MarkdownWidget::class . '::link' => new Style(color: self::INFO, underline: true),
            MarkdownWidget::class . '::link-url' => new Style(color: self::MUTED),
            MarkdownWidget::class . '::code' => new Style(color: self::ACCENT),
            MarkdownWidget::class . '::code-block-border' => new Style(color: self::BORDER),
            MarkdownWidget::class . '::quote' => new Style(color: self::SECONDARY, italic: true),
            MarkdownWidget::class . '::quote-border' => new Style(color: self::BORDER),
            MarkdownWidget::class . '::hr' => new Style(color: self::BORDER),
            MarkdownWidget::class . '::list-bullet' => new Style(color: self::ACCENT),
            MarkdownWidget::class . '::bold' => new Style(bold: true),
            MarkdownWidget::class . '::italic' => new Style(italic: true),
            MarkdownWidget::class . '::strikethrough' => new Style(strikethrough: true),
        ]);
    }

    public function before(TokenType $tokenType): string
    {
        $color = match ($tokenType) {
            TokenTypeEnum::KEYWORD, TokenTypeEnum::VALUE => self::ACCENT,
            TokenTypeEnum::TYPE => self::SUCCESS,
            TokenTypeEnum::PROPERTY, TokenTypeEnum::VARIABLE, TokenTypeEnum::GENERIC, TokenTypeEnum::ATTRIBUTE => self::INFO,
            TokenTypeEnum::COMMENT => self::MUTED,
            default => self::TEXT,
        };

        return (new Style(color: $color))->getAnsiRestore();
    }

    public function after(TokenType $tokenType): string
    {
        return (new Style(color: self::TEXT))->getAnsiRestore();
    }
}
