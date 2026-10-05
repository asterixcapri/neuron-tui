<?php

declare(strict_types=1);

namespace NeuronTui\Tests\View;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;
use NeuronTui\View\HistoryView;
use NeuronTui\View\MessageKind;
use NeuronTui\View\MessageView;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;

use function implode;
use function rtrim;
use function substr_count;

final class HistoryViewTest extends TestCase
{
    public function testScrollingKeepsReadingPositionWhileResponseGrows(): void
    {
        $history = new HistoryView();
        $history->beginTurn('Question');
        $history->appendResponse("Line 1\nLine 2\nLine 3\nLine 4\nLine 5\nLine 6");
        $renderer = new Renderer();
        $context = new RenderContext(40, 3);
        $renderer->renderWidget($history, $context);
        $history->scroll(3);
        $before = $renderer->renderWidget($history, $context);
        $history->appendResponse("\nLine 7\nLine 8");
        self::assertSame($before, $renderer->renderWidget($history, $context));
        $history->scroll(-100);
        $latest = AnsiUtils::stripAnsiCodes(implode("\n", $renderer->renderWidget($history, $context)));
        self::assertStringContainsString('Line 8', $latest);
        $history->scroll(100);
        self::assertStringContainsString('❯ Question', AnsiUtils::stripAnsiCodes(implode("\n", $renderer->renderWidget($history, $context))));
        $history->load([new UserMessage('New history')]);
        self::assertStringContainsString('❯ New history', AnsiUtils::stripAnsiCodes(implode("\n", $renderer->renderWidget($history, $context))));
    }

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
        $live->showToolCall($tool);
        $live->showToolResult($tool);
        $live->appendResponse('It is sunny');
        $live->finishTurn();
        $renderer = new Renderer();
        $context = new RenderContext(60, 16);
        $screen = implode("\n", $renderer->renderWidget($stored, $context));
        self::assertSame($screen, implode("\n", $renderer->renderWidget($live, $context)));
        $text = AnsiUtils::stripAnsiCodes($screen);
        self::assertStringContainsString('❯ Weather?', $text);
        self::assertStringContainsString('● Checking', $text);
        self::assertStringNotContainsString('◆ weather', $text);
        self::assertSame(1, substr_count($text, '● weather'));
        self::assertStringContainsString('● It is sunny', $text);
    }

    public function testToolParametersAndCompletionUpdateTheSameCall(): void
    {
        $history = new HistoryView();
        $history->showToolCall(new ToolCall('read_file', 'first', ['path' => 'examples/basic.php', 'start_line' => 1]));
        $history->showToolCall(new ToolCall('read_file', 'second', ['path' => 'README.md']));
        $renderer = new Renderer();
        $context = new RenderContext(80, 12);
        $pending = AnsiUtils::stripAnsiCodes(implode("\n", $renderer->renderWidget($history, $context)));
        self::assertStringContainsString('◆ read_file(path: "examples/basic.php", start_line: 1)', $pending);
        $history->showToolResult((new ToolCall('read_file', 'first'))->setResult('Long output hidden'));
        $completed = implode("\n", $renderer->renderWidget($history, $context));
        $text = AnsiUtils::stripAnsiCodes($completed);
        self::assertSame(2, substr_count($text, 'read_file('));
        self::assertStringContainsString('● read_file(path: "examples/basic.php", start_line: 1)', $text);
        self::assertStringContainsString('◆ read_file(path: "README.md")', $text);
        self::assertStringNotContainsString('Long output hidden', $text);
        self::assertStringContainsString('38;2;145;185;154', $completed);
    }

    public function testNestedToolParametersAndErrorsAreReadable(): void
    {
        $call = new ToolCall('search', 'nested', ['query' => 'neuron', 'filters' => ['extensions' => ['php'], 'limit' => 10]]);
        $history = new HistoryView();
        $history->showToolCall($call);
        $renderer = new Renderer();
        $context = new RenderContext(60, 20);
        $text = AnsiUtils::stripAnsiCodes(implode("\n", $renderer->renderWidget($history, $context)));
        self::assertStringContainsString("◆ search\n  {", $text);
        self::assertStringContainsString('      "filters": {', $text);
        self::assertStringContainsString('          "limit": 10', $text);
        $history->showToolResult((new ToolCall('search', 'nested'))->setResult(ToolOutput::error('File not found')));
        $screen = implode("\n", $renderer->renderWidget($history, $context));
        $text = AnsiUtils::stripAnsiCodes($screen);
        self::assertStringContainsString('! search', $text);
        self::assertStringContainsString('  └ File not found', $text);
        self::assertSame(1, substr_count($text, 'search'));
        self::assertStringContainsString('38;2;232;139;139', $screen);
        $history->load([]);
        $history->showToolResult((new ToolCall('search', 'nested', ['query' => 'new']))->setResult('Done'));
        $reloaded = AnsiUtils::stripAnsiCodes(implode("\n", $renderer->renderWidget($history, $context)));
        self::assertStringContainsString('● search(query: "new")', $reloaded);
        self::assertStringNotContainsString('filters', $reloaded);
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
