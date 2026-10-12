<?php

declare(strict_types=1);

namespace NeuronTui\Tests\View;

use NeuronAI\Tools\ToolCall;
use NeuronTui\View\ToolView;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;

use function array_map;
use function implode;
use function mb_strlen;
use function rtrim;
use function str_repeat;

final class ToolViewTest extends TestCase
{
    public function testLongWriteArgumentsHaveABoundedUnicodePreview(): void
    {
        $inputs = ['path' => 'example.txt', 'content' => str_repeat('è', 500) . 'HIDDEN_TAIL'];
        $tool = new ToolCall('write', 'write-1', $inputs);
        $view = new ToolView($tool);
        $text = $this->render($view);

        self::assertStringContainsString('● write(path: "example.txt", content: "è', $text);
        self::assertStringEndsWith('…)', $text);
        self::assertSame(300 + mb_strlen('● write()'), mb_strlen($text));
        self::assertStringNotContainsString('HIDDEN_TAIL', $text);
        self::assertSame($inputs, $tool->getInputs());
    }

    public function testNestedArgumentsUseTheSamePreviewLimit(): void
    {
        $view = new ToolView(new ToolCall('write', 'write-2', [
            'files' => ['example.txt' => str_repeat('x', 500) . 'HIDDEN_TAIL'],
        ]));
        $text = $this->render($view);

        self::assertStringContainsString('● write', $text);
        self::assertStringContainsString('"files": {', $text);
        self::assertStringEndsWith('…', $text);
        self::assertLessThan(340, mb_strlen($text));
        self::assertStringNotContainsString('HIDDEN_TAIL', $text);
    }

    private function render(ToolView $view): string
    {
        $lines = (new Renderer())->renderWidget($view, new RenderContext(1000, 20));

        return implode("\n", array_map(
            static fn(string $line): string => rtrim(AnsiUtils::stripAnsiCodes($line)),
            $lines,
        ));
    }
}
