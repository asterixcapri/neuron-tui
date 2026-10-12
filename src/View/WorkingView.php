<?php

declare(strict_types=1);

namespace NeuronTui\View;

use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\LoaderWidget;

use function array_slice;
use function array_values;
use function hrtime;
use function max;

/** @internal */
final class WorkingView extends LoaderWidget
{
    private float $elapsed = 0.0;
    private float $lastTick = 0.0;

    public function __construct()
    {
        parent::__construct('Working (0s)');
    }

    public function start(): void
    {
        if ($this->isRunning()) {
            return;
        }
        $this->elapsed = 0.0;
        $this->lastTick = hrtime(true) / 1e9;
        $this->setMessage('Working (0s)');
        parent::start();
    }

    public function tick(?float $deltaTime = null): bool
    {
        if (!$this->isRunning()) {
            return false;
        }
        $now = hrtime(true) / 1e9;
        $this->elapsed += max(0.0, $deltaTime ?? $now - $this->lastTick);
        $this->lastTick = $now;
        $message = 'Working (' . (int) $this->elapsed . 's)';
        $changed = $message !== $this->getMessage();
        $this->setMessage($message);

        return parent::tick($deltaTime) || $changed;
    }

    /** @return list<string> */
    public function render(RenderContext $context): array
    {
        return array_values(array_slice(parent::render($context), 1));
    }
}
