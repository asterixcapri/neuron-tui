<?php

declare(strict_types=1);

namespace NeuronCli\Tui;

use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Ansi\TextWrapper;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The optional explanation beneath a Picker title.
 *
 * @internal
 */
final class PickerDescription extends AbstractWidget
{
    private const int MAX_LINES = 3;

    public function __construct(private readonly string $text)
    {
    }

    /** @return string[] */
    public function render(RenderContext $context): array
    {
        return $this->linesForWidth($context->getColumns());
    }

    public function heightForWidth(int $columns): int
    {
        return count($this->linesForWidth($columns));
    }

    /** @return string[] */
    private function linesForWidth(int $columns): array
    {
        $normalized = preg_replace(
            '/\s+/u',
            ' ',
            trim(DisplayableText::safe($this->text)),
        ) ?? '';

        $lines = TextWrapper::wrapTextWithAnsi(
            $normalized,
            max(1, $columns),
        );

        if (count($lines) <= self::MAX_LINES) {
            return $lines;
        }

        $last = self::MAX_LINES - 1;

        return [
            ...array_slice($lines, 0, $last),
            AnsiUtils::truncateToWidth(
                $lines[$last] . '…',
                max(1, $columns),
                '…',
            ),
        ];
    }
}
