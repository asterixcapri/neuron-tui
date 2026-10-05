<?php

declare(strict_types=1);

namespace NeuronTui\Tests\View;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\ToolCall;
use NeuronTui\View\HistoryView;
use NeuronTui\View\MessageKind;
use NeuronTui\View\MessageView;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;

use function implode;
use function rtrim;

final class HistoryViewTest extends TestCase
{
    public function testAgentMarkdownIsRenderedDuringStreamingAndWhenLoaded(): void
    {
        $markdown = "**Hello** with `code`\n\n- First item\n- Second item\n\n```php\necho 'Hi';\n```";
        $stored = new HistoryView();
        $stored->load([new UserMessage('**Question**'), new AssistantMessage($markdown)]);
        $live = new HistoryView();
        $live->beginTurn('**Question**');
        $live->appendResponse('**Hel');
        $renderer = new Renderer();
        $context = new RenderContext(40, 20);
        self::assertStringContainsString('Hel', AnsiUtils::stripAnsiCodes(implode("\n", $renderer->renderWidget($live, $context))));
        $live->appendResponse("lo** with `code`\n\n- First item\n- Second item\n\n```php\necho 'Hi';\n```");
        $screen = implode("\n", $renderer->renderWidget($live, $context));
        self::assertSame(implode("\n", $renderer->renderWidget($stored, $context)), $screen);
        $text = AnsiUtils::stripAnsiCodes($screen);
        self::assertStringContainsString('❯ **Question**', $text);
        self::assertStringContainsString('● Hello with code', $text);
        self::assertStringContainsString('  • First item', $text);
        self::assertStringContainsString("echo 'Hi';", $text);
        self::assertStringNotContainsString('**Hello**', $text);
        self::assertStringNotContainsString('```', $text);
        self::assertStringContainsString("\033[1m", $screen);
        foreach ($renderer->renderWidget($live, $context) as $line) {
            self::assertLessThanOrEqual(40, AnsiUtils::visibleWidth($line));
        }
    }

    public function testStoredAndStreamedMessagesHaveTheSamePresentation(): void
    {
        $tool = (new ToolCall('weather', 'call-1'))->setResult('Sunny');
        $stored = new HistoryView();
        $stored->load([
            new UserMessage('Weather?'),
            new ToolCallMessage('Checking', [$tool]),
            new ToolResultMessage([$tool]),
            new AssistantMessage('It is sunny'),
        ]);
        $live = new HistoryView();
        $live->beginTurn('Weather?');
        $live->appendResponse('Checking');
        $live->notify('weather …', MessageKind::ToolCall);
        $live->notify('weather completed', MessageKind::ToolResult);
        $live->appendResponse('It is sunny');
        $live->finishTurn();
        $renderer = new Renderer();
        $context = new RenderContext(60, 16);
        $screen = implode("\n", $renderer->renderWidget($stored, $context));
        self::assertSame($screen, implode("\n", $renderer->renderWidget($live, $context)));
        $text = AnsiUtils::stripAnsiCodes($screen);
        self::assertStringContainsString('❯ Weather?', $text);
        self::assertStringContainsString('● Checking', $text);
        self::assertStringContainsString('◆ weather …', $text);
        self::assertStringContainsString('└ weather completed', $text);
        self::assertStringContainsString('● It is sunny', $text);
    }

    public function testUserHighlightAndAgentContinuationLines(): void
    {
        $renderer = new Renderer();
        $context = new RenderContext(16, 8);
        $user = implode("\n", $renderer->renderWidget(new MessageView('Hello', MessageKind::User), $context));
        self::assertStringContainsString('48;2;56;56;56', $user);
        $agent = $renderer->renderWidget(new MessageView("First line\nSecond line", MessageKind::Agent), $context);
        self::assertSame('● First line', rtrim(AnsiUtils::stripAnsiCodes($agent[0])));
        self::assertSame('  Second line', rtrim(AnsiUtils::stripAnsiCodes($agent[1])));
        $wrapped = $renderer->renderWidget(new MessageView('One two three four five', MessageKind::Agent), $context);
        self::assertStringStartsWith('  ', AnsiUtils::stripAnsiCodes($wrapped[1]));
    }

    public function testSystemNoticesAndErrorsStayDistinctFromAgentResponses(): void
    {
        $history = new HistoryView();
        $history->load([new SystemMessage('Rules')]);
        $history->notify('Information');
        $history->notify('Failed', MessageKind::Error);
        $text = AnsiUtils::stripAnsiCodes(implode("\n", (new Renderer())->renderWidget($history, new RenderContext(60, 10))));
        self::assertStringContainsString('· Rules', $text);
        self::assertStringContainsString('ℹ Information', $text);
        self::assertStringContainsString('! Failed', $text);
        self::assertStringNotContainsString('●', $text);
    }
}
