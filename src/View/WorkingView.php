<?php

declare(strict_types=1);

namespace NeuronTui\View;

use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\LoaderWidget;

use function array_slice;
use function array_values;

/** @internal */
final class WorkingView extends LoaderWidget
{
    public function __construct()
    {
        parent::__construct('Working…');
    }

    /** @return list<string> */
    public function render(RenderContext $context): array
    {
        return array_values(array_slice(parent::render($context), 1));
    }
}
