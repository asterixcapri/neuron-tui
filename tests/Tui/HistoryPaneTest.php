<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Tui;

use NeuronTui\Tui\ConversationStyleSheet;
use NeuronTui\Tui\HistoryPane;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class HistoryPaneTest extends TestCase
{
    private ?Tui $tui = null;

    public function testAnEntryIsUpdatedThroughItsHandle(): void
    {
        $terminal = new VirtualTerminal(rows: 24);
        $pane = $this->pane($terminal);

        $answer = $pane->addMessage('●', 'The answer', 'agent');
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
        $pane->addMessage('❯', 'What we discussed before', 'user');

        $pane->clear();
        $pane->addMessage('❯', 'What we discuss now', 'user');

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
            $pane->addMessage('●', "old {$line}", 'agent');
        }

        $pane->scrollUp();
        $pane->clear();

        for ($line = 1; $line <= 3; $line++) {
            $pane->addMessage('●', "fresh {$line}", 'agent');
        }

        $display = $this->paint($terminal);

        self::assertStringContainsString('fresh 3', $display);
    }

    public function testTheReadingPositionSurvivesContentGrowingBelowIt(): void
    {
        $terminal = new VirtualTerminal(rows: 10);
        $pane = $this->pane($terminal);

        for ($line = 1; $line <= 20; $line++) {
            $pane->addMessage('●', "line {$line}", 'agent');
        }

        $pane->scrollUp();
        $anchored = $this->paint($terminal);
        self::assertStringContainsString('line 13', $anchored);
        self::assertStringNotContainsString('line 18', $anchored);

        $growing = $pane->addNote('', 'tool');
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
            $pane->addMessage('●', "line {$line}", 'agent');
        }

        $pane->scrollUp();
        $pane->scrollDown();
        $pane->addMessage('●', 'line 21', 'agent');

        $terminal->clearOutput();
        $display = $this->paint($terminal);

        self::assertStringContainsString('line 21', $display);
    }

    public function testARemovedEntryFreesTheHeightItOccupied(): void
    {
        $terminal = new VirtualTerminal(rows: 10);
        $pane = $this->pane($terminal);

        for ($line = 1; $line <= 20; $line++) {
            $pane->addMessage('●', "line {$line}", 'agent');
        }

        $working = $pane->addNote('✶ Working (0s)', 'loading');
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
