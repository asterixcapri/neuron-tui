<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Command;

use Closure;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronInteraction\Command\CommandAdapterInterface;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Conversation;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronTui\Command\TuiCommandAdapter;
use NeuronTui\Tests\History\StoredConversation;
use NeuronTui\Turn\TurnScheduler;
use NeuronTui\View\ConversationView;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

use function Amp\delay;

final class CommandHistoryTest extends TestCase
{
    private SessionStore $sessionStore;
    private VirtualTerminal $terminal;
    private ConversationView $view;
    private TurnScheduler $scheduler;
    private Conversation $conversation;

    protected function setUp(): void
    {
        $agent = (new Agent())->setThreadId('test-thread');
        $storage = new InMemoryStorage();
        $this->sessionStore = new SessionStore($storage, 'test-user');
        $session = $this->sessionStore->create();
        StoredConversation::turn($this->sessionStore, $session, new UserMessage('Earlier conversation'));

        $this->terminal = new VirtualTerminal(rows: 30);
        $this->view = new ConversationView($this->terminal, 'Neuron AI', 'Conversation');
        $this->conversation = new Conversation($agent, $this->sessionStore, session: $session);
        $this->scheduler = new TurnScheduler($this->conversation, $this->view);
        $this->scheduler->synchronizeHistory();
    }

    public function testCompletionDisplaysTheSelectedSession(): void
    {
        $replacement = $this->sessionStore->create();
        StoredConversation::turn($this->sessionStore, $replacement, new UserMessage('Replacement conversation'));

        $this->runCommand(static function (CommandAdapterInterface $adapter) use ($replacement): void {
            $adapter->useSession($replacement);
            self::assertSame($replacement->getKey(), $adapter->session()->getKey());
        });

        $display = $this->display();
        self::assertStringContainsString('Replacement conversation', $display);
        self::assertStringNotContainsString('Earlier conversation', $display);
    }

    public function testSelectingTheCurrentSessionRefreshesItsChangedHistory(): void
    {
        $session = $this->conversation->session();
        StoredConversation::turn($this->sessionStore, $session, new UserMessage('A later question'), new AssistantMessage('Added after the initial display'));

        $this->runCommand(static function (CommandAdapterInterface $adapter) use ($session): void {
            $adapter->useSession($session);
        });

        self::assertStringContainsString('Added after the initial display', $this->display());
    }

    public function testMessagesAfterAHistoryChangeSurviveCompletionAndTheNextInvocation(): void
    {
        $this->runCommand(static function (CommandAdapterInterface $adapter): void {
            $adapter->useSession($adapter->sessionStore()->create());
            $adapter->notify('Session changed');
            $adapter->warn('A warning remains');
            $adapter->error('An expected error remains');
        });
        $this->runCommand(static function (CommandAdapterInterface $adapter): void {});

        $display = $this->display();
        self::assertStringContainsString('Session changed', $display);
        self::assertStringContainsString('A warning remains', $display);
        self::assertStringContainsString('An expected error remains', $display);
        self::assertStringNotContainsString('Earlier conversation', $display);
    }

    public function testAPromptAfterAHistoryChangeRemainsVisibleAtCompletion(): void
    {
        $this->runCommand(static function (CommandAdapterInterface $adapter): void {
            $adapter->useSession($adapter->sessionStore()->create());
            $adapter->promptAgent(new UserMessage('Question in the new conversation'));
        });

        $display = $this->display();
        self::assertStringContainsString('Question in the new conversation', $display);
        self::assertStringNotContainsString('Earlier conversation', $display);
    }

    public function testAgentReplacementKeepsTheSelectedSessionAndDisplayedHistory(): void
    {
        $currentSession = $this->scheduler->session();
        $before = $currentSession->getMessages();
        $replacement = (new Agent())->setAiProvider(new FakeAIProvider(new AssistantMessage('Replacement answer')));
        $this->scheduler->useAgent($replacement);
        self::assertSame($currentSession, $this->scheduler->session());
        self::assertSame($currentSession->getKey(), $this->scheduler->agent()->getThreadId());
        self::assertEquals($before, $currentSession->getMessages());
        $this->scheduler->enqueueMessage(new UserMessage('Continue the selected conversation'));
        for ($tick = 0; $tick < 10 && $this->scheduler->isBusy(); ++$tick) {
            $this->scheduler->tick();
            delay(0);
        }
        self::assertFalse($this->scheduler->isBusy());
        $messages = $currentSession->getMessages();
        self::assertSame('Replacement answer', $messages[3]->getContent());
        $display = $this->display();
        self::assertStringContainsString('Earlier conversation', $display);
        self::assertStringContainsString('Replacement answer', $display);
    }

    public function testSelectingAForeignSessionLeavesTheConversationUnchanged(): void
    {
        $foreign = (new SessionStore(new InMemoryStorage(), 'other-user'))->create();
        $before = $this->scheduler->agent();

        $this->runCommand(static function (CommandAdapterInterface $adapter) use ($foreign): void {
            $adapter->useSession($foreign);
        });

        self::assertSame($before, $this->scheduler->agent());
        $display = $this->display();
        self::assertStringContainsString('The selected Session does not belong to this', $display);
        self::assertStringContainsString('Earlier conversation', $display);
    }

    /** @param Closure(CommandAdapterInterface<null>): void $run */
    private function runCommand(Closure $run): void
    {
        $command = new class ($run) implements CommandInterface {
            /** @param Closure(CommandAdapterInterface<null>): void $run */
            public function __construct(private readonly Closure $run) {}

            public function name(): string
            {
                return '/probe';
            }

            public function describe(): string
            {
                return 'Change the conversation.';
            }

            /** @param CommandAdapterInterface<null> $adapter */
            public function run(CommandAdapterInterface $adapter, string $value): void
            {
                ($this->run)($adapter);
            }
        };
        $commands = (new Commands())->addCommand($command);
        $storage = new InMemoryStorage();
        $commands->run('/probe', '', new TuiCommandAdapter(
            $this->scheduler,
            $this->view,
            $commands,
            $this->conversation,
            new ConfigurationStore($storage, 'test-user'),
        ));
    }

    private function display(): string
    {
        $this->view->paintPendingChanges();

        return AnsiUtils::stripAnsiCodes($this->terminal->getOutput());
    }
}
