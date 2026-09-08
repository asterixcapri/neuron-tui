<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Conversation;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronTui\Conversation\ConversationRuntime;
use NeuronTui\Conversation\TuiAdapter;
use NeuronTui\Tests\Support\SelfConfiguredAgent;
use NeuronTui\View\ConversationView;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class TuiAdapterTest extends TestCase
{
    public function testUseAgentActivatesAndDisplaysTheSuppliedConversation(): void
    {
        $original = new Agent(threadId: 'A');
        $original->getChatHistory()->addMessage(new UserMessage('Conversation A'));
        $terminal = new VirtualTerminal(rows: 24);
        $view = new ConversationView($terminal, 'Test', 'Conversation');
        $adapter = $this->adapter($original, $view);
        $view->showHistory($original->getChatHistory()->getMessages());
        $view->paintPendingChanges();
        $terminal->clearOutput();
        $replacement = new SelfConfiguredAgent(threadId: 'B');
        $replacement->getChatHistory()->addMessage(new UserMessage('Conversation B'));

        $adapter->useAgent($replacement);
        $view->paintPendingChanges();

        self::assertSame($replacement, $adapter->agent());
        self::assertSame('B', $replacement->getThreadId());
        self::assertSame('Conversation A', $original->getChatHistory()->getMessages()[0]->getContent());
        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString('Conversation B', $display);
        self::assertStringNotContainsString('Conversation A', $display);
    }

    public function testReplacingAnAgentWithTheSameHistoryPreservesNotices(): void
    {
        $original = new Agent();
        $history = new InMemoryChatHistory();
        $history->addMessage(new UserMessage('Retained conversation'));
        $original->setChatHistory($history);
        $terminal = new VirtualTerminal(rows: 24);
        $view = new ConversationView($terminal, 'Test', 'Conversation');
        $adapter = $this->adapter($original, $view);
        $view->showHistory($history->getMessages());
        $adapter->say('Retained notice');
        $view->paintPendingChanges();
        $terminal->clearOutput();
        $replacement = new Agent();
        $replacement->setChatHistory($history);

        $adapter->useAgent($replacement);
        $view->paintPendingChanges();
        self::assertSame('', $terminal->getOutput(), 'Replacing only the Agent must not reset the displayed History or notices.');
        $adapter->say('Replacement active');
        $view->paintPendingChanges();

        self::assertSame($replacement, $adapter->agent());
        self::assertSame($history, $replacement->getChatHistory());
        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString('Replacement active', $display);
    }

    private function adapter(Agent $agent, ?ConversationView $view = null): TuiAdapter
    {
        $view ??= new ConversationView(new VirtualTerminal(), 'Test', 'Conversation');

        return new TuiAdapter(
            new ConversationRuntime($agent, $view),
            $view,
            new Commands(),
            new SessionStore(new InMemoryStorage(), 'test-user'),
            new \NeuronInteraction\Agent\AgentFactoryRegistry(),
            new \NeuronInteraction\Configuration\ConfigurationStore(new InMemoryStorage(), 'test-user'),
        );
    }
}
