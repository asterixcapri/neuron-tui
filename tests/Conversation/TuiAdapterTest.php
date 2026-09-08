<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Conversation;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
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
    public function testNewAgentRecreatesTheCurrentClassWithItsDefaultsWithoutActivatingIt(): void
    {
        $current = new SelfConfiguredAgent(threadId: 'original');
        $changedProvider = new FakeAIProvider(new AssistantMessage('Changed answer.'));
        $current->setAiProvider($changedProvider);
        $current->chat(new UserMessage('Original question.'));
        $adapter = $this->adapter($current);

        $fresh = $adapter->newAgent();

        self::assertInstanceOf(SelfConfiguredAgent::class, $fresh);
        self::assertNotSame($current, $fresh);
        self::assertSame($current, $adapter->agent());
        self::assertNull($fresh->getThreadId());
        self::assertNotSame($changedProvider, $fresh->getProvider());
        $fresh->setChatHistory(new InMemoryChatHistory('new'));
        self::assertSame('An answer.', $fresh->chat(new UserMessage('New question.'))->getMessage()?->getContent());
        self::assertSame('original', $current->getThreadId());
        self::assertCount(2, $current->getChatHistory()->getMessages());
    }

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
        self::assertInstanceOf(SelfConfiguredAgent::class, $adapter->newAgent());
    }

    public function testConstructionFailureLeavesTheCurrentAgentInPlace(): void
    {
        $current = new class('required') extends Agent {
            public function __construct(string $required)
            {
                parent::__construct(threadId: $required);
            }
        };
        $adapter = $this->adapter($current);

        try {
            $adapter->newAgent();
            self::fail('The constructor requires an argument.');
        } catch (\ArgumentCountError) {
            self::assertSame($current, $adapter->agent());
            self::assertSame('required', $current->getThreadId());
        }
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
