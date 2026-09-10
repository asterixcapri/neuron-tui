<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Conversation;

use NeuronTui\Conversation\CommandInput;
use NeuronTui\Conversation\MessageForAgent;
use NeuronTui\Conversation\SubmissionParser;
use PHPUnit\Framework\TestCase;

final class SubmissionParserTest extends TestCase
{
    public function testOrdinaryTextIsAMessageForTheAgent(): void
    {
        $submission = SubmissionParser::parse("First line\nsecond line");

        self::assertInstanceOf(MessageForAgent::class, $submission);
        self::assertSame("First line\nsecond line", $submission->content);
    }

    public function testAMessageKeepsEveryCharacterIncludingItsSpacing(): void
    {
        $submission = SubmissionParser::parse("  Type /clear to start over \n");

        self::assertInstanceOf(MessageForAgent::class, $submission);
        self::assertSame(
            "  Type /clear to start over \n",
            $submission->content,
        );
    }

    public function testACommandOnItsOwnHasNoArguments(): void
    {
        $submission = SubmissionParser::parse('/exit');

        self::assertInstanceOf(CommandInput::class, $submission);
        self::assertSame('/exit', $submission->name);
        self::assertSame('', $submission->value);
    }

    public function testWhitespaceAroundACommandIsNotAnArgument(): void
    {
        $submission = SubmissionParser::parse("/clear \n");

        self::assertInstanceOf(CommandInput::class, $submission);
        self::assertSame('/clear', $submission->name);
        self::assertSame('', $submission->value);
    }

    public function testWhatFollowsTheNameIsTheArguments(): void
    {
        $submission = SubmissionParser::parse('/exit now');

        self::assertInstanceOf(CommandInput::class, $submission);
        self::assertSame('/exit', $submission->name);
        self::assertSame('now', $submission->value);
    }

    public function testTheArgumentsKeepTheirOwnSpacingButNotTheOuterOne(): void
    {
        $submission = SubmissionParser::parse("/review  the  diff \t");

        self::assertInstanceOf(CommandInput::class, $submission);
        self::assertSame('/review', $submission->name);
        self::assertSame('the  diff', $submission->value);
    }

    public function testWhateverEndsTheNameIsNotThenPartOfTheArguments(): void
    {
        $submission = SubmissionParser::parse("/exit\x0Cnow");

        self::assertInstanceOf(CommandInput::class, $submission);
        self::assertSame('/exit', $submission->name);
        self::assertSame('now', $submission->value);
    }

    public function testANameNoCommandAnswersToIsStillReadAsAName(): void
    {
        $submission = SubmissionParser::parse("/unknown with\targuments");

        self::assertInstanceOf(CommandInput::class, $submission);
        self::assertSame('/unknown', $submission->name);
        self::assertSame("with\targuments", $submission->value);
    }

    public function testTextMentioningACommandIsStillAMessage(): void
    {
        $submission = SubmissionParser::parse('Type /clear to start over');

        self::assertInstanceOf(MessageForAgent::class, $submission);
        self::assertSame('Type /clear to start over', $submission->content);
    }
}
