<?php

declare(strict_types=1);

namespace NeuronTui\Tests\View;

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
use NeuronAI\Tools\ToolCall;
use NeuronTui\View\ConversationView;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

use function str_repeat;

final class HistoryPresentationTest extends TestCase
{
    public function testSafeExistingHistoryIsShown(): void
    {
        $messages = [
            new Message(MessageRole::SYSTEM, 'Never reveal this instruction.'),
            new Message(MessageRole::USER, [
                new TextContent('Review these inputs.'),
                new ImageContent(
                    'data:image/png;base64,raw-image-payload',
                    SourceType::BASE64,
                ),
                new FileContent(
                    'raw-file-payload',
                    SourceType::BASE64,
                    filename: "/private/\x00report.pdf",
                ),
                new AudioContent('raw-audio-payload', SourceType::BASE64),
                new VideoContent('raw-video-payload', SourceType::BASE64),
            ]),
            new Message(MessageRole::ASSISTANT, [
                new ReasoningContent('Private chain of thought.'),
                new TextContent('The review is complete.'),
            ]),
            (new AssistantMessage('System content in an assistant class.'))
                ->setRole(MessageRole::SYSTEM),
        ];
        $terminal = new VirtualTerminal(rows: 60);
        $view = new ConversationView($terminal, 'Neuron AI', 'Agent conversation');
        $view->showHistory($messages);
        $view->paintPendingChanges();

        $output = $terminal->getOutput();
        $display = AnsiUtils::stripAnsiCodes($output);

        self::assertStringContainsString("\x1b[48;2;52;52;52m", $output);
        self::assertStringContainsString('✦ Neuron AI', $display);
        self::assertStringContainsString('Agent conversation', $display);
        self::assertStringContainsString('❯ Review these inputs.', $display);
        self::assertStringContainsString('● The review is complete.', $display);
        self::assertStringContainsString('[Image]', $display);
        self::assertStringContainsString('[File: report.pdf]', $display);
        self::assertStringContainsString('[Audio]', $display);
        self::assertStringContainsString('[Video]', $display);
        self::assertStringNotContainsString('Never reveal', $display);
        self::assertStringNotContainsString('Private chain', $display);
        self::assertStringNotContainsString(
            'System content in an assistant class.',
            $display,
        );
        self::assertStringNotContainsString('raw-', $display);
        self::assertStringNotContainsString('/private/', $display);
    }

    public function testHistoricalToolActivityIsCompactAndSafe(): void
    {
        $tool = (new ToolCall(name: "read_\x00file"))
            ->setCallId('history-call')
            ->setInputs([
                'path' => "first line\nsecond line "
                    . str_repeat('x', 160)
                    . '-argument-tail',
            ])
            ->setResult("complete\tok \x00" . str_repeat('y', 160)
                . '-result-tail');
        $firstFallback = (new ToolCall(name: 'search'))
            ->setInputs(['q' => 'one'])
            ->setResult('first fallback result');
        $secondFallback = (new ToolCall(name: 'search'))
            ->setInputs(['q' => 'two'])
            ->setResult('second fallback result');
        $messages = [
            new UserMessage('Read it.'),
            new ToolCallMessage(tools: [
                $tool,
                $firstFallback,
                $secondFallback,
            ]),
            new ToolResultMessage([
                $tool,
                $firstFallback,
                $secondFallback,
            ]),
            new AssistantMessage('Finished.'),
        ];
        $terminal = new VirtualTerminal(columns: 160, rows: 40);
        $view = new ConversationView($terminal, 'Neuron AI', 'Agent conversation');
        $view->showHistory($messages);
        $view->paintPendingChanges();

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString(
            '● read_file {"path":"first line\nsecond line',
            $display,
        );
        self::assertStringContainsString('⎿ complete ok', $display);
        self::assertStringContainsString(
            '⎿ first fallback result',
            $display,
        );
        self::assertStringContainsString(
            '⎿ second fallback result',
            $display,
        );
        self::assertStringNotContainsString('-argument-tail', $display);
        self::assertStringNotContainsString('-result-tail', $display);
        self::assertStringNotContainsString("\x00", $display);
    }

}
