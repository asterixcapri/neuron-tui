<?php

declare(strict_types=1);

namespace NeuronCli\Tests\Conversation;

use NeuronCli\Conversation\MessageForAgent;
use NeuronCli\Conversation\SlashCommand;
use NeuronCli\Conversation\Submission;
use NeuronCli\Conversation\UnknownSlashCommand;
use PHPUnit\Framework\TestCase;

final class SubmissionTest extends TestCase
{
    public function testOrdinaryTextIsAMessageForTheAgent(): void
    {
        $submission = Submission::interpret("First line\nsecond line");

        self::assertInstanceOf(MessageForAgent::class, $submission);
        self::assertSame("First line\nsecond line", $submission->contents);
    }

    public function testTheThreeSlashCommandsAreRecognized(): void
    {
        self::assertSame(
            SlashCommand::Clear,
            Submission::interpret('/clear'),
        );
        self::assertSame(
            SlashCommand::Sessions,
            Submission::interpret('/sessions'),
        );
        self::assertSame(
            SlashCommand::Exit,
            Submission::interpret('/exit'),
        );
    }

    public function testAnythingElseBeginningWithASlashIsUnknown(): void
    {
        $submission = Submission::interpret('/unknown');

        self::assertInstanceOf(UnknownSlashCommand::class, $submission);
        self::assertSame('/unknown', $submission->name);
    }

    public function testAnUnknownCommandIsNamedByItsFirstWordAlone(): void
    {
        $submission = Submission::interpret("/unknown with\targuments");

        self::assertInstanceOf(UnknownSlashCommand::class, $submission);
        self::assertSame('/unknown', $submission->name);
    }

    public function testACommandFollowedByAnythingIsNotThatCommand(): void
    {
        $submission = Submission::interpret('/exit now');

        self::assertInstanceOf(UnknownSlashCommand::class, $submission);
        self::assertSame('/exit', $submission->name);
    }

    public function testTextMentioningASlashCommandIsStillAMessage(): void
    {
        $submission = Submission::interpret('Type /clear to start over');

        self::assertInstanceOf(MessageForAgent::class, $submission);
        self::assertSame('Type /clear to start over', $submission->contents);
    }
}
