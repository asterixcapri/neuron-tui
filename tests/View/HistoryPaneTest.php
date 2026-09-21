<?php

declare(strict_types=1);

namespace NeuronTui\Tests\View;

use NeuronTui\View\ConversationStyleSheet;
use NeuronTui\View\HistoryEntryKind;
use NeuronTui\View\HistoryPane;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class HistoryPaneTest extends TestCase
{
    private ?Tui $tui = null;

    public function testEventsWrapUnderTheirContentAndMeasureTheirRenderedHeight(): void
    {
        $terminal = new VirtualTerminal(columns: 20, rows: 24);
        $pane = $this->pane($terminal);
        $entry = $pane->addEntry(
            HistoryEntryKind::Warning,
            "Alpha beta gamma delta  \nNext line",
        );

        $display = $this->paint($terminal);

        self::assertMatchesRegularExpression('/ ! Alpha beta gamma *\r?\n/', $display);
        self::assertMatchesRegularExpression('/   delta *\r?\n/', $display);
        self::assertMatchesRegularExpression('/   Next line *\r?\n/', $display);
        self::assertSame(3, $entry->height());
    }

    public function testEventGrowthPreservesTheReadingPosition(): void
    {
        $terminal = new VirtualTerminal(columns: 20, rows: 10);
        $pane = $this->pane($terminal);

        for ($line = 1; $line <= 20; $line++) {
            $pane->addEntry(HistoryEntryKind::Notice, "line {$line}");
        }

        $pane->scrollUp();
        $before = $this->paint($terminal);
        $pane->addEntry(HistoryEntryKind::Error, 'Alpha beta gamma delta epsilon zeta');
        $terminal->clearOutput();

        self::assertSame($before, $this->paint($terminal));
    }

    public function testAnEntryIsUpdatedThroughItsHandle(): void
    {
        $terminal = new VirtualTerminal(rows: 24);
        $pane = $this->pane($terminal);

        $answer = $pane->addEntry(HistoryEntryKind::AssistantMessage, 'The answer');
        $answer->appendText(' is forty-two.');

        $display = $this->paint($terminal);

        self::assertStringContainsString(
            'The answer is forty-two.',
            $display,
        );
    }

    public function testAClearedPaneCanPaintADifferentHistory(): void
    {
        $terminal = new VirtualTerminal(rows: 24);
        $pane = $this->pane($terminal);
        $pane->addEntry(HistoryEntryKind::UserMessage, 'What we discussed before');

        $pane->clear();
        $pane->addEntry(HistoryEntryKind::UserMessage, 'What we discuss now');

        $display = $this->paint($terminal);

        self::assertStringContainsString('What we discuss now', $display);
        self::assertStringNotContainsString(
            'What we discussed before',
            $display,
        );
    }

    public function testAClearedPaneStartsCountingHeightsAgain(): void
    {
        $terminal = new VirtualTerminal(rows: 8);
        $pane = $this->pane($terminal);

        for ($line = 1; $line <= 20; $line++) {
            $pane->addEntry(HistoryEntryKind::AssistantMessage, "old {$line}");
        }

        $pane->scrollUp();
        $pane->clear();

        for ($line = 1; $line <= 3; $line++) {
            $pane->addEntry(HistoryEntryKind::AssistantMessage, "fresh {$line}");
        }

        $display = $this->paint($terminal);

        self::assertStringContainsString('fresh 3', $display);
    }

    public function testTheReadingPositionSurvivesContentGrowingBelowIt(): void
    {
        $terminal = new VirtualTerminal(rows: 10);
        $pane = $this->pane($terminal);

        for ($line = 1; $line <= 20; $line++) {
            $pane->addEntry(HistoryEntryKind::AssistantMessage, "line {$line}");
        }

        $pane->scrollUp();
        $anchored = $this->paint($terminal);
        self::assertStringContainsString('line 13', $anchored);
        self::assertStringNotContainsString('line 18', $anchored);

        $growing = $pane->addEntry(HistoryEntryKind::ToolActivity, '');
        $growing->setText("grown 1\ngrown 2\ngrown 3");

        $terminal->clearOutput();
        $afterGrowth = $this->paint($terminal);

        self::assertStringContainsString('line 13', $afterGrowth);
        self::assertStringNotContainsString('line 18', $afterGrowth);
        self::assertStringNotContainsString('grown', $afterGrowth);
    }

    public function testScrollingBackDownResumesFollowingTheLatestEntry(): void
    {
        $terminal = new VirtualTerminal(rows: 10);
        $pane = $this->pane($terminal);

        for ($line = 1; $line <= 20; $line++) {
            $pane->addEntry(HistoryEntryKind::AssistantMessage, "line {$line}");
        }

        $pane->scrollUp();
        $pane->scrollDown();
        $pane->addEntry(HistoryEntryKind::AssistantMessage, 'line 21');

        $terminal->clearOutput();
        $display = $this->paint($terminal);

        self::assertStringContainsString('line 21', $display);
    }

    public function testARemovedEntryFreesTheHeightItOccupied(): void
    {
        $terminal = new VirtualTerminal(rows: 10);
        $pane = $this->pane($terminal);

        for ($line = 1; $line <= 20; $line++) {
            $pane->addEntry(HistoryEntryKind::AssistantMessage, "line {$line}");
        }

        $working = $pane->addEntry(HistoryEntryKind::WorkingIndicator, '✶ Working (0s)');
        $pane->scrollUp();
        $anchored = $this->paint($terminal);
        self::assertStringContainsString('line 14', $anchored);
        self::assertStringNotContainsString('line 13', $anchored);

        $pane->remove($working);

        $terminal->clearOutput();
        $display = $this->paint($terminal);

        self::assertStringNotContainsString('Working', $display);
        self::assertStringContainsString('line 14', $display);
        self::assertStringNotContainsString('line 13', $display);
    }

    private function pane(VirtualTerminal $terminal): HistoryPane
    {
        $this->tui = new Tui(ConversationStyleSheet::create(), $terminal);
        $pane = new HistoryPane($this->tui, $terminal);
        $this->tui->add($pane->widget());

        return $pane;
    }

    private function paint(VirtualTerminal $terminal): string
    {
        self::assertInstanceOf(Tui::class, $this->tui);
        $this->tui->processRender();

        return AnsiUtils::stripAnsiCodes($terminal->getOutput());
    }
}
