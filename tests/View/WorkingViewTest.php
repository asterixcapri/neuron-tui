<?php

declare(strict_types=1);

namespace NeuronTui\Tests\View;

use NeuronTui\View\WorkingView;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

use function implode;

final class WorkingViewTest extends TestCase
{
    public function testElapsedSecondsAdvanceAndResetForEachTurn(): void
    {
        $working = new WorkingView();
        $context = new RenderContext(50, 1);
        $working->start();
        self::assertStringContainsString('Working (0s)', implode("\n", $working->render($context)));
        $working->tick(0.99);
        self::assertSame('Working (0s)', $working->getMessage());
        $working->tick(0.02);
        self::assertSame('Working (1s)', $working->getMessage());
        $working->tick(10.0);
        self::assertStringContainsString(
            'Working (11s)',
            AnsiUtils::stripAnsiCodes(implode("\n", $working->render($context))),
        );
        self::assertStringNotContainsString('esc', $working->getMessage());
        $working->start();
        self::assertSame('Working (11s)', $working->getMessage());
        $working->stop();
        self::assertFalse($working->tick(5.0));
        self::assertSame([], $working->render($context));
        $working->start();
        self::assertSame('Working (0s)', $working->getMessage());
        $working->tick(1.1);
        self::assertSame('Working (1s)', $working->getMessage());
        $working->stop();
    }
}
