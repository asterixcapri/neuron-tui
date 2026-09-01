<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Tui;

use NeuronTui\Tui\DisplayableText;
use PHPUnit\Framework\TestCase;

final class DisplayableTextTest extends TestCase
{
    public function testSafeTextKeepsOrdinaryText(): void
    {
        self::assertSame(
            "Hello,\n\tworld 🙂",
            DisplayableText::safe("Hello,\n\tworld 🙂"),
        );
    }

    public function testSafeTextRemovesTerminalEscapeIntroducers(): void
    {
        self::assertSame(
            '[2Jwiped',
            DisplayableText::safe("\x1b[2J\x07wiped"),
        );
    }

    public function testSafeTextRemovesEightBitControlIntroducers(): void
    {
        self::assertSame(
            '[2J',
            DisplayableText::safe("\xc2\x9b[2J"),
        );
    }

    public function testSafeTextKeepsTabsAndNewlines(): void
    {
        self::assertSame(
            "one\n\ttwo",
            DisplayableText::safe("one\r\n\ttwo"),
        );
    }

    public function testSafeTextDropsInvalidUtf8(): void
    {
        self::assertSame('ok', DisplayableText::safe("o\xffk"));
    }

    public function testSafeTextSanitizesBeforeStrippingControlBytes(): void
    {
        // A stray 0xc2 followed by a control byte and 0x9b must not be
        // spliced into a surviving 8-bit CSI introducer.
        self::assertSame(
            '[2J',
            DisplayableText::safe("\xc2\x00\x9b[2J"),
        );
    }

    public function testPreviewCollapsesWhitespaceIntoOneLine(): void
    {
        self::assertSame(
            'one two three',
            DisplayableText::preview("  one \n\n two\t\tthree  ", 80),
        );
    }

    public function testSingleLineAppliesSanitizationAndWhitespaceNormalization(): void
    {
        self::assertSame(
            '[2J one two',
            DisplayableText::singleLine("\x1b[2J \n\t one  two "),
        );
    }

    public function testSingleLineOfOnlyWhitespaceAndControlBytesIsEmpty(): void
    {
        self::assertSame('', DisplayableText::singleLine(" \n\t\x00\x07 "));
    }

    public function testPreviewIsSafe(): void
    {
        self::assertSame(
            '[2J ok',
            DisplayableText::preview("\x1b[2J\n\xffok", 80),
        );
    }

    public function testPreviewTruncatesAtTheDisplayWidth(): void
    {
        self::assertSame(
            'abcd…',
            DisplayableText::preview('abcdefghij', 5),
        );
    }

    public function testPreviewMeasuresWidthRatherThanCharacters(): void
    {
        // Each of these characters is two columns wide.
        self::assertSame('世…', DisplayableText::preview('世界世界', 4));
    }

    public function testPreviewLeavesTextWithinTheWidthAlone(): void
    {
        self::assertSame('abcde', DisplayableText::preview('abcde', 5));
    }

    public function testPreviewOfEmptyTextIsEmpty(): void
    {
        self::assertSame('', DisplayableText::preview("\x1b \n", 80));
    }
}
