<?php

declare(strict_types=1);

namespace NeuronCli\Tui;

use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\EditorWidget;

/**
 * @internal
 */
final class ComposerEditor extends EditorWidget
{
    /**
     * @return string[]
     */
    public function render(RenderContext $context): array
    {
        return array_slice(parent::render($context), 1, -1);
    }
}
