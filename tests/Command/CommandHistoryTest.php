<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Command;

use Closure;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Command\CommandAdapterInterface;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Conversation\ConversationRuntime;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronTui\Command\TuiCommandAdapter;
use NeuronTui\Conversation\ConversationController;
use NeuronTui\Tests\History\SessionHistory;
use NeuronTui\View\ConversationView;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

use function array_map;

final class CommandHistoryTest extends TestCase
{
    private SessionStore $sessionStore;
    private VirtualTerminal $terminal;
    private ConversationView $view;
    private ConversationController $controller;

    protected function setUp(): void
    {
        $agent = (new Agent())->setThreadId('test-thread');
        $this->sessionStore = new SessionStore(new InMemoryStorage(), 'test-user');
        $session = $this->sessionStore->create();
        $history = SessionHistory::of($session);
        $history->addMessage(new UserMessage('Earlier conversation'));

        $this->terminal = new VirtualTerminal(rows: 30);
        $this->view = new ConversationView($this->terminal, 'Neuron AI', 'Conversation');
        $this->controller = new ConversationController(new ConversationRuntime($agent, $this->sessionStore, session: $session), $this->view);
        $this->controller->synchronizeHistory();
    }

    public function testCompletionDisplaysTheSelectedSession(): void
    {
        $replacement = $this->sessionStore->create();
        SessionHistory::of($replacement)->addMessage(new UserMessage('Replacement conversation'));

        $this->runCommand(static function (CommandAdapterInterface $adapter) use ($replacement): void {
            $adapter->useSession($replacement);
            self::assertSame($replacement->getKey(), $adapter->session()->getKey());
        });

        $display = $this->display();
        self::assertStringContainsString('Replacement conversation', $display);
        self::assertStringNotContainsString('Earlier conversation', $display);
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

    public function testAgentReplacementKeepsTheSessionAndItsArchivedMessages(): void
    {
        $key = $this->controller->agent()->getChatHistory()->getThreadId();
        $session = $this->sessionStore->read($key);
        self::assertNotNull($session);
        $session->messageStore()->archive($key, 1);
        SessionHistory::of($session)->addMessage(new UserMessage('Active question'));

        $currentSession = $this->controller->session();
        $this->controller->useAgent(new Agent());
        self::assertSame($currentSession, $this->controller->session());

        self::assertSame($key, $this->controller->agent()->getThreadId());
        self::assertSame(['Active question'], array_map(static fn(Message $message): ?string => $message->getContent(), $this->controller->agent()->getChatHistory()->getMessages()));
        self::assertSame(['Earlier conversation', 'Active question'], array_map(static fn(Message $message): ?string => $message->getContent(), $session->getMessages()));
        $this->controller->agent()->getChatHistory()->addMessage(new AssistantMessage('Replacement answer'));
        self::assertCount(3, $session->getMessages());
    }

    public function testSelectingAForeignSessionLeavesTheConversationUnchanged(): void
    {
        $foreign = (new SessionStore(new InMemoryStorage(), 'other-user'))->create();
        $before = $this->controller->agent();

        $this->runCommand(static function (CommandAdapterInterface $adapter) use ($foreign): void {
            $adapter->useSession($foreign);
        });

        self::assertSame($before, $this->controller->agent());
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
            $this->controller,
            $this->view,
            $commands,
            $this->sessionStore,
            new ConfigurationStore($storage, 'test-user'),
        ));
    }

    private function display(): string
    {
        $this->view->paintPendingChanges();

        return AnsiUtils::stripAnsiCodes($this->terminal->getOutput());
    }
}
