<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Tui;

use NeuronAI\Agent\Agent;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\HelpCommand;
use NeuronTui\Tui;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Component\Tui\Terminal\ScreenBuffer;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class ComposerGeometryTest extends TestCase
{
    public function testComposerShowsFromOneToFiveEditableLines(): void
    {
        $terminal = new VirtualTerminal(rows: 16);
        $screen = new ScreenBuffer(80, 16);
        $screens = [];

        EventLoop::delay(
            0.02,
            static fn () => $terminal->simulateInput('line 1'),
        );

        for ($line = 1; $line <= 6; ++$line) {
            EventLoop::delay(
                0.1 * $line - 0.05,
                static fn () => $terminal->simulateResize(80, 16),
            );
        }

        EventLoop::delay(
            0.1,
            static function () use ($terminal, $screen, &$screens): void {
                $screens[1] = self::captureScreen($terminal, $screen);
                $terminal->simulateInput("\x1b[13;2uline 2");
            },
        );

        for ($line = 2; $line <= 6; ++$line) {
            EventLoop::delay(
                0.1 * $line,
                static function () use (
                    $line,
                    $terminal,
                    $screen,
                    &$screens,
                ): void {
                    $screens[$line] = self::captureScreen(
                        $terminal,
                        $screen,
                    );

                    if ($line < 6) {
                        $terminal->simulateInput(
                            "\x1b[13;2uline " . ($line + 1),
                        );

                        return;
                    }

                    $terminal->simulateInput("\x03");
                },
            );
        }

        (new Tui(new Agent(), terminal: $terminal))->run();

        self::assertCount(6, $screens);
        $geometry = array_map(self::composerGeometry(...), $screens);

        for ($line = 1; $line <= 5; ++$line) {
            self::assertSame(
                $line,
                $geometry[$line]['bottom'] - $geometry[$line]['top'] - 1,
            );
            self::assertSame(
                $geometry[$line]['top'] + 1,
                $geometry[$line]['prompt'],
            );
        }

        self::assertSame(
            5,
            $geometry[6]['bottom'] - $geometry[6]['top'] - 1,
        );
        self::assertSame(
            $geometry[6]['top'] + 1,
            $geometry[6]['prompt'],
        );
        self::assertSame(2, $geometry[1]['bottom'] - $geometry[1]['top']);
        self::assertStringNotContainsString(
            'line 1',
            implode("\n", $screens[6]),
        );
        self::assertStringContainsString('line 6', implode("\n", $screens[6]));
    }

    public function testStatusRemainsOneTruncatedRowOnANarrowTerminal(): void
    {
        $terminal = new VirtualTerminal(columns: 24, rows: 16);
        $screen = new ScreenBuffer(24, 16);
        $lines = [];

        EventLoop::delay(
            0.05,
            static fn () => $terminal->simulateResize(24, 16),
        );
        EventLoop::delay(
            0.1,
            static function () use ($terminal, $screen, &$lines): void {
                $lines = self::captureScreen($terminal, $screen);
                $terminal->simulateInput("\x03");
            },
        );

        (new Tui(new Agent(), terminal: $terminal))->run();

        $geometry = self::composerGeometry($lines);

        self::assertSame(
            'ready · Enter sends ·...',
            rtrim($lines[$geometry['status']]),
        );
        self::assertSame('', trim($lines[$geometry['bottom'] + 1]));
        self::assertSame(
            [],
            array_filter(
                array_slice($lines, $geometry['status'] + 1),
                static fn (string $line): bool => trim($line) !== '',
            ),
        );
    }

    public function testSuggestionsPreserveComposerAndStatusGeometry(): void
    {
        $terminal = new VirtualTerminal(rows: 30);
        $screen = new ScreenBuffer(80, 30);
        $open = [];
        $closed = [];

        EventLoop::delay(
            0.05,
            static fn () => $terminal->simulateInput('/'),
        );
        EventLoop::delay(
            0.1,
            static fn () => $terminal->simulateResize(80, 30),
        );
        EventLoop::delay(
            0.15,
            static function () use ($terminal, $screen, &$open): void {
                $open = self::captureScreen($terminal, $screen);
                $terminal->simulateInput("\x1b");
            },
        );
        EventLoop::delay(
            0.2,
            static fn () => $terminal->simulateResize(80, 30),
        );
        EventLoop::delay(
            0.25,
            static function () use ($terminal, $screen, &$closed): void {
                $closed = self::captureScreen($terminal, $screen);
                $terminal->simulateInput("\x03");
            },
        );

        (new Tui(
            new Agent(),
            terminal: $terminal,
            commands: new Commands([new HelpCommand()]),
        ))->run();

        $openGeometry = self::composerGeometry($open);
        $closedGeometry = self::composerGeometry($closed);

        foreach ([$openGeometry, $closedGeometry] as $geometry) {
            self::assertSame(
                1,
                $geometry['bottom'] - $geometry['top'] - 1,
            );
            self::assertSame($geometry['top'] + 1, $geometry['prompt']);
        }
        self::assertStringStartsWith(
            'suggesting ·',
            trim($open[$openGeometry['status']]),
        );
        self::assertStringStartsWith(
            'ready ·',
            trim($closed[$closedGeometry['status']]),
        );
    }

    /**
     * @return string[]
     */
    private static function captureScreen(
        VirtualTerminal $terminal,
        ScreenBuffer $screen,
    ): array {
        $screen->write($terminal->consumeOutput());

        return $screen->getLines();
    }

    /**
     * @param string[] $lines
     *
     * @return array{top: int, prompt: int, bottom: int, status: int}
     */
    private static function composerGeometry(array $lines): array
    {
        $prompt = array_find_key(
            $lines,
            static fn (string $line): bool => str_starts_with(
                trim($line),
                '❯',
            ),
        );
        self::assertIsInt($prompt);

        $top = null;
        for ($row = $prompt - 1; $row >= 0; --$row) {
            if (str_starts_with($lines[$row], '─')) {
                $top = $row;
                break;
            }
        }
        self::assertIsInt($top);

        $bottom = null;
        for ($row = $prompt + 1; $row < count($lines); ++$row) {
            if (str_starts_with($lines[$row], '─')) {
                $bottom = $row;
                break;
            }
        }
        self::assertIsInt($bottom);

        $status = null;
        for ($row = $bottom + 1; $row < count($lines); ++$row) {
            if (trim($lines[$row]) !== '') {
                $status = $row;
                break;
            }
        }
        self::assertIsInt($status);

        return [
            'top' => $top,
            'prompt' => $prompt,
            'bottom' => $bottom,
            'status' => $status,
        ];
    }
}
