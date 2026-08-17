<?php

declare(strict_types=1);

namespace NeuronCli\Tests\Tui;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronCli\Tui\ConversationView;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class ConversationViewTest extends TestCase
{
    public function testAHistoryCanBePaintedAtAnyMomentNotOnlyAtStartup(): void
    {
        $terminal = new VirtualTerminal(rows: 24);
        $view = new ConversationView($terminal, 'Neuron AI', 'Conversation');

        $view->showHistory([
            new UserMessage('What we discussed before'),
            new AssistantMessage('An answer from before'),
        ]);
        $view->showHistory([
            new UserMessage('What we discuss now'),
        ]);
        $view->paintPendingChanges();

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());

        self::assertStringContainsString('❯ What we discuss now', $display);
        self::assertStringNotContainsString(
            'What we discussed before',
            $display,
        );
        self::assertStringNotContainsString(
            'An answer from before',
            $display,
        );
    }
}
