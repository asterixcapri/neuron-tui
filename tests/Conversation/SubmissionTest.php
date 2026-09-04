<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Conversation;

use NeuronTui\Conversation\MessageForAgent;
use NeuronTui\Conversation\CommandInput;
use NeuronTui\Conversation\Submission;
use PHPUnit\Framework\TestCase;

final class SubmissionTest extends TestCase
{
    public function testOrdinaryTextIsAMessageForTheAgent(): void
    {
        $submission = Submission::interpret("First line\nsecond line");

        self::assertInstanceOf(MessageForAgent::class, $submission);
        self::assertSame("First line\nsecond line", $submission->contents);
    }

    public function testAMessageKeepsEveryCharacterIncludingItsSpacing(): void
    {
        $submission = Submission::interpret("  Type /clear to start over \n");

        self::assertInstanceOf(MessageForAgent::class, $submission);
        self::assertSame(
            "  Type /clear to start over \n",
            $submission->contents,
        );
    }

    public function testACommandOnItsOwnHasNoArguments(): void
    {
        $submission = Submission::interpret('/exit');

        self::assertInstanceOf(CommandInput::class, $submission);
        self::assertSame('/exit', $submission->name);
        self::assertSame('', $submission->arguments->text);
    }

    public function testWhitespaceAroundACommandIsNotAnArgument(): void
    {
        $submission = Submission::interpret("/clear \n");

        self::assertInstanceOf(CommandInput::class, $submission);
        self::assertSame('/clear', $submission->name);
        self::assertSame('', $submission->arguments->text);
    }

    public function testWhatFollowsTheNameIsTheArguments(): void
    {
        $submission = Submission::interpret('/exit now');

        self::assertInstanceOf(CommandInput::class, $submission);
        self::assertSame('/exit', $submission->name);
        self::assertSame('now', $submission->arguments->text);
    }

    public function testTheArgumentsKeepTheirOwnSpacingButNotTheOuterOne(): void
    {
        $submission = Submission::interpret("/review  the  diff \t");

        self::assertInstanceOf(CommandInput::class, $submission);
        self::assertSame('/review', $submission->name);
        self::assertSame('the  diff', $submission->arguments->text);
    }

    public function testWhateverEndsTheNameIsNotThenPartOfTheArguments(): void
    {
        $submission = Submission::interpret("/exit\x0Cnow");

        self::assertInstanceOf(CommandInput::class, $submission);
        self::assertSame('/exit', $submission->name);
        self::assertSame('now', $submission->arguments->text);
    }

    public function testANameNoCommandAnswersToIsStillReadAsAName(): void
    {
        $submission = Submission::interpret("/unknown with\targuments");

        self::assertInstanceOf(CommandInput::class, $submission);
        self::assertSame('/unknown', $submission->name);
        self::assertSame("with\targuments", $submission->arguments->text);
    }

    public function testTextMentioningACommandIsStillAMessage(): void
    {
        $submission = Submission::interpret('Type /clear to start over');

        self::assertInstanceOf(MessageForAgent::class, $submission);
        self::assertSame('Type /clear to start over', $submission->contents);
    }
}
