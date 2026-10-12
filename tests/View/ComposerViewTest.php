<?php

declare(strict_types=1);

namespace NeuronTui\Tests\View;

use NeuronTui\View\ComposerView;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;

use function count;
use function implode;
use function str_repeat;

final class ComposerViewTest extends TestCase
{
    public function testQueuePreviewStaysCompactAndFitsANarrowTerminal(): void
    {
        $composer = new ComposerView();
        $renderer = new Renderer();
        $context = new RenderContext(30, 18);
        $before = $renderer->renderWidget($composer, $context);
        $composer->setPendingMessages([
            "First\nsecond line",
            str_repeat('Long message ', 20),
            "Third\033]0;title\007 message",
            'Fourth message',
            'Fifth message',
        ]);
        $lines = $renderer->renderWidget($composer, $context);
        $screen = AnsiUtils::stripAnsiCodes(implode("\n", $lines));
        self::assertStringContainsString('• Messages queued', $screen);
        self::assertStringContainsString('↳ First second line', $screen);
        self::assertStringContainsString('↳ Long message', $screen);
        self::assertStringContainsString('↳ Third]0;title message', $screen);
        self::assertStringNotContainsString('Fourth message', $screen);
        self::assertStringContainsString('… +2 more', $screen);
        self::assertStringNotContainsString("\033]0;title", implode("\n", $lines));
        self::assertSame(count($before) + 7, count($lines));
        foreach ($lines as $line) {
            self::assertLessThanOrEqual(30, AnsiUtils::visibleWidth($line));
        }
        $composer->setPendingMessages([]);
        self::assertSame($before, $renderer->renderWidget($composer, $context));
    }
}
