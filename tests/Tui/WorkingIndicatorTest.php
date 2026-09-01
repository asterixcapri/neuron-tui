<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Tui;

use NeuronTui\Tui\ConversationStyleSheet;
use NeuronTui\Tui\HistoryPane;
use NeuronTui\Tui\WorkingIndicator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class WorkingIndicatorTest extends TestCase
{
    private ?Tui $tui = null;

    private ?HistoryPane $pane = null;

    public function testTheElapsedCounterFollowsTheTimeItIsGiven(): void
    {
        $terminal = new VirtualTerminal(rows: 24);
        $indicator = $this->indicator($terminal);

        $indicator->start(1_000.0);
        self::assertStringContainsString(
            '✶ Working (0s)',
            $this->paint($terminal),
        );

        $terminal->clearOutput();
        $indicator->advance(1_003.5);

        self::assertStringContainsString(
            'Working (3s)',
            $this->paint($terminal),
        );
    }

    public function testTheFrameOnlyChangesOnceTheRedrawIntervalHasPassed(): void
    {
        $terminal = new VirtualTerminal(rows: 24);
        $indicator = $this->indicator($terminal);

        $indicator->start(1_000.0);
        $indicator->advance(1_000.05);

        self::assertStringNotContainsString('✸', $this->paint($terminal));

        $indicator->advance(1_000.09);

        self::assertStringContainsString(
            '✸ Working (0s)',
            $this->paint($terminal),
        );
    }

    public function testPausingLetsAnotherEntryBePaintedBelowTheIndicator(): void
    {
        $terminal = new VirtualTerminal(rows: 24);
        $indicator = $this->indicator($terminal);
        $pane = $this->pane;
        self::assertInstanceOf(HistoryPane::class, $pane);

        $indicator->start(1_000.0);
        $indicator->whilePaused(
            1_002.0,
            static function () use ($pane): void {
                $pane->addNote('⎿ tool result', 'tool');
            },
        );

        $display = $this->paint($terminal);
        $resultPosition = strpos($display, 'tool result');
        $workingPosition = strpos($display, 'Working');

        self::assertNotFalse($resultPosition);
        self::assertNotFalse($workingPosition);
        self::assertGreaterThan($resultPosition, $workingPosition);
        self::assertSame(1, substr_count($display, 'Working'));
        self::assertStringContainsString('Working (2s)', $display);
    }

    public function testStoppingTakesTheIndicatorOffScreen(): void
    {
        $terminal = new VirtualTerminal(rows: 24);
        $indicator = $this->indicator($terminal);

        $indicator->start(1_000.0);
        $indicator->stop();

        self::assertStringNotContainsString(
            'Working',
            $this->paint($terminal),
        );
    }

    public function testAnIndicatorThatIsNotShowingIgnoresEveryOtherCall(): void
    {
        $terminal = new VirtualTerminal(rows: 24);
        $indicator = $this->indicator($terminal);

        $indicator->stop();
        $indicator->advance(1_000.0);
        $indicator->whilePaused(1_000.0, static function (): void {});
        $indicator->stop();

        self::assertStringNotContainsString(
            'Working',
            $this->paint($terminal),
        );
    }

    private function indicator(VirtualTerminal $terminal): WorkingIndicator
    {
        $this->tui = new Tui(ConversationStyleSheet::create(), $terminal);
        $this->pane = new HistoryPane($this->tui, $terminal);
        $this->tui->add($this->pane->widget());

        return new WorkingIndicator($this->pane);
    }

    private function paint(VirtualTerminal $terminal): string
    {
        self::assertInstanceOf(Tui::class, $this->tui);
        $this->tui->processRender();

        return AnsiUtils::stripAnsiCodes($terminal->getOutput());
    }
}
