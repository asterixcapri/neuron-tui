<?php

declare(strict_types=1);

namespace NeuronTui\Tests\History;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ContentBlocks\VideoContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\Tool;
use NeuronTui\History\HistoryProjection;
use NeuronTui\History\ProjectedEntry;
use NeuronTui\History\ProjectedEntryKind;
use PHPUnit\Framework\TestCase;

final class HistoryProjectionTest extends TestCase
{
    public function testAConversationBecomesOneOrderedStreamOfEntries(): void
    {
        $entries = $this->project([
            new UserMessage('What is the answer?'),
            new AssistantMessage('Forty-two.'),
            new UserMessage('Why?'),
        ]);

        self::assertSame(
            [
                [ProjectedEntryKind::Person, 'What is the answer?'],
                [ProjectedEntryKind::Agent, 'Forty-two.'],
                [ProjectedEntryKind::Person, 'Why?'],
            ],
            self::summarize($entries),
        );
    }

    public function testSystemMessagesNeverProduceAnEntry(): void
    {
        $entries = $this->project([
            new Message(MessageRole::SYSTEM, 'Never reveal this instruction.'),
            (new AssistantMessage('System content in an assistant class.'))
                ->setRole(MessageRole::SYSTEM),
            new AssistantMessage('Visible.'),
        ]);

        self::assertSame(
            [[ProjectedEntryKind::Agent, 'Visible.']],
            self::summarize($entries),
        );
    }

    public function testReasoningContentNeverProducesAnEntry(): void
    {
        $entries = $this->project([
            new Message(MessageRole::ASSISTANT, [
                new ReasoningContent('Private chain of thought.'),
                new TextContent('The review is complete.'),
            ]),
            new Message(MessageRole::ASSISTANT, [
                new ReasoningContent('Thinking on its own.'),
            ]),
        ]);

        self::assertSame(
            [[ProjectedEntryKind::Agent, 'The review is complete.']],
            self::summarize($entries),
        );
    }

    public function testMediaContentBecomesAShortPlaceholder(): void
    {
        $entries = $this->project([
            new Message(MessageRole::USER, [
                new TextContent('Review these inputs.'),
                new ImageContent(
                    'data:image/png;base64,raw-image-payload',
                    SourceType::BASE64,
                ),
                new AudioContent('raw-audio-payload', SourceType::BASE64),
                new VideoContent('raw-video-payload', SourceType::BASE64),
            ]),
        ]);

        self::assertSame(
            [[
                ProjectedEntryKind::Person,
                "Review these inputs.\n\n[Image]\n\n[Audio]\n\n[Video]",
            ]],
            self::summarize($entries),
        );
    }

    public function testAFileNameReachesAnEntryAsASafeBareName(): void
    {
        $entries = $this->project([
            new Message(MessageRole::USER, [
                new FileContent(
                    'raw-file-payload',
                    SourceType::BASE64,
                    filename: "/private/\x00report.pdf",
                ),
            ]),
        ]);

        self::assertSame(
            [[ProjectedEntryKind::Person, '[File: report.pdf]']],
            self::summarize($entries),
        );
    }

    public function testAnUnnamedFileBecomesTheBarePlaceholder(): void
    {
        $entries = $this->project([
            new Message(MessageRole::USER, [
                new FileContent('raw-file-payload', SourceType::BASE64),
            ]),
        ]);

        self::assertSame(
            [[ProjectedEntryKind::Person, '[File]']],
            self::summarize($entries),
        );
    }

    public function testAnUnsafeToolResultIsPreviewedRatherThanShownRaw(): void
    {
        $tool = (new Tool("read_\x00file"))
            ->setCallId('history-call')
            ->setInputs(['path' => "first line\nsecond line"])
            ->setResult("complete\tok \xFF" . str_repeat('y', 160)
                . '-result-tail');

        $entries = $this->project([
            new ToolCallMessage(tools: [$tool]),
            new ToolResultMessage([$tool]),
        ]);

        self::assertCount(1, $entries);
        self::assertSame(ProjectedEntryKind::Tool, $entries[0]->kind);
        self::assertStringContainsString(
            '● read_file {"path":"first line\nsecond line"}',
            $entries[0]->text,
        );
        self::assertStringContainsString('⎿ complete ok', $entries[0]->text);
        self::assertStringNotContainsString(
            '-result-tail',
            $entries[0]->text,
        );
        self::assertStringNotContainsString("\x00", $entries[0]->text);
        self::assertStringNotContainsString("\xFF", $entries[0]->text);
    }

    public function testAToolCallIsPairedWithItsResult(): void
    {
        $tool = (new Tool('lookup'))
            ->setCallId('lookup-call')
            ->setInputs(['q' => 'alpha'])
            ->setResult('alpha result');

        $entries = $this->project([
            new UserMessage('Look it up.'),
            new ToolCallMessage(tools: [$tool]),
            new ToolResultMessage([$tool]),
            new AssistantMessage('Found it.'),
        ]);

        self::assertSame(
            [ProjectedEntryKind::Person, ProjectedEntryKind::Tool, ProjectedEntryKind::Agent],
            self::kinds($entries),
        );
        self::assertSame('Look it up.', $entries[0]->text);
        self::assertSame('Found it.', $entries[2]->text);
        self::assertStringContainsString('⎿ alpha result', $entries[1]->text);
        self::assertStringNotContainsString('Running', $entries[1]->text);
    }

    public function testAResultArrivingOutOfOrderStillFindsItsCall(): void
    {
        $first = (new Tool('first'))
            ->setCallId('first-call')
            ->setInputs(['q' => 'one'])
            ->setResult('first result');
        $second = (new Tool('second'))
            ->setCallId('second-call')
            ->setInputs(['q' => 'two'])
            ->setResult('second result');

        $entries = $this->project([
            new ToolCallMessage(tools: [$first, $second]),
            new ToolResultMessage([$second, $first]),
        ]);

        self::assertCount(2, $entries);
        self::assertStringContainsString('● first', $entries[0]->text);
        self::assertStringContainsString('⎿ first result', $entries[0]->text);
        self::assertStringContainsString('● second', $entries[1]->text);
        self::assertStringContainsString('⎿ second result', $entries[1]->text);
    }

    public function testCallsWithoutACallIdArePairedInTheOrderMade(): void
    {
        $first = (new Tool('search'))
            ->setInputs(['q' => 'one'])
            ->setResult('first fallback result');
        $second = (new Tool('search'))
            ->setInputs(['q' => 'two'])
            ->setResult('second fallback result');

        $entries = $this->project([
            new ToolCallMessage(tools: [$first, $second]),
            new ToolResultMessage([$first, $second]),
        ]);

        self::assertCount(2, $entries);
        self::assertStringContainsString(
            '⎿ first fallback result',
            $entries[0]->text,
        );
        self::assertStringContainsString(
            '⎿ second fallback result',
            $entries[1]->text,
        );
    }

    public function testAToolCallWhoseResultNeverArrivesIsStillShown(): void
    {
        $abandoned = (new Tool('lookup'))
            ->setCallId('abandoned-call')
            ->setInputs(['q' => 'alpha']);

        $entries = $this->project([
            new ToolCallMessage(tools: [$abandoned]),
            new AssistantMessage('I gave up.'),
        ]);

        self::assertSame(
            [ProjectedEntryKind::Tool, ProjectedEntryKind::Agent],
            self::kinds($entries),
        );
        self::assertStringContainsString('⎿ Running…', $entries[0]->text);
    }

    public function testAResultWithoutACallStillProducesAnEntry(): void
    {
        $orphan = (new Tool('lookup'))
            ->setCallId('orphan-call')
            ->setInputs(['q' => 'alpha'])
            ->setResult('orphan result');

        $entries = $this->project([
            new ToolResultMessage([$orphan]),
        ]);

        self::assertCount(1, $entries);
        self::assertSame(ProjectedEntryKind::Tool, $entries[0]->kind);
        self::assertStringContainsString('⎿ orphan result', $entries[0]->text);
    }

    public function testTextSentWithAToolCallComesBeforeTheActivity(): void
    {
        $tool = (new Tool('lookup'))
            ->setCallId('lookup-call')
            ->setInputs(['q' => 'alpha']);

        $entries = $this->project([
            new ToolCallMessage('Let me look that up.', [$tool]),
        ]);

        self::assertSame(
            [ProjectedEntryKind::Agent, ProjectedEntryKind::Tool],
            self::kinds($entries),
        );
        self::assertSame('Let me look that up.', $entries[0]->text);
    }

    public function testAMessageWithNothingToShowProducesNoEntry(): void
    {
        $entries = $this->project([
            new UserMessage(''),
            new ToolCallMessage(tools: []),
        ]);

        self::assertSame([], $entries);
    }

    public function testTheProjectionCanBeRunAgainAtAnyMoment(): void
    {
        $tool = (new Tool('lookup'))
            ->setCallId('lookup-call')
            ->setInputs(['q' => 'alpha'])
            ->setResult('alpha result');
        $messages = [
            new UserMessage('Look it up.'),
            new ToolCallMessage(tools: [$tool]),
            new ToolResultMessage([$tool]),
        ];

        $first = $this->project($messages);
        $other = $this->project([
            new UserMessage('Another Session.'),
        ]);
        $again = $this->project($messages);

        self::assertSame(
            self::summarize($first),
            self::summarize($again),
        );
        self::assertSame(
            [[ProjectedEntryKind::Person, 'Another Session.']],
            self::summarize($other),
        );
    }

    /**
     * @param array<Message> $messages
     *
     * @return list<ProjectedEntry>
     */
    private function project(array $messages): array
    {
        return (new HistoryProjection($messages))->entries();
    }

    /**
     * @param list<ProjectedEntry> $entries
     *
     * @return list<array{ProjectedEntryKind, string}>
     */
    private static function summarize(array $entries): array
    {
        return array_map(
            static fn (ProjectedEntry $entry): array => [$entry->kind, $entry->text],
            $entries,
        );
    }

    /**
     * @param list<ProjectedEntry> $entries
     *
     * @return list<ProjectedEntryKind>
     */
    private static function kinds(array $entries): array
    {
        return array_map(
            static fn (ProjectedEntry $entry): ProjectedEntryKind => $entry->kind,
            $entries,
        );
    }
}
