<?php

declare(strict_types=1);

namespace NeuronTui\View;

use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\EditorWidget;

use function array_values;
use function count;
use function max;

/** @internal */
final class ComposerInputView extends EditorWidget
{
    public function __construct()
    {
        parent::__construct();
        $this->setMinVisibleLines(1)->setStyle(new Style(color: Theme::SECONDARY));
    }

    /** @return list<string> */
    public function render(RenderContext $context): array
    {
        $columns = $context->getColumns();
        $lines = array_values(parent::render($context->withColumns(max(1, $columns - 2))));
        $last = count($lines) - 1;
        $prompt = (new Style(color: Theme::PROMPT))->apply('❯')
            . (new Style(color: Theme::SECONDARY))->getAnsiRestore() . ' ';
        $border = (new Style(color: Theme::BORDER))->apply('──');
        foreach ($lines as $index => $line) {
            $prefix = $index === 0 || $index === $last ? $border : ($index === 1 ? $prompt : '  ');
            $lines[$index] = AnsiUtils::truncateToWidth($prefix . $line, max(1, $columns), '');
        }

        return $lines;
    }
}
