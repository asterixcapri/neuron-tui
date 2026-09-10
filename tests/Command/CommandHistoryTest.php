<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Command;

use Closure;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Command\CommandAdapterInterface;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronTui\Command\TuiCommandAdapter;
use NeuronTui\Conversation\ConversationRuntime;
use NeuronTui\View\ConversationView;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class CommandHistoryTest extends TestCase
{
    private VirtualTerminal $terminal;
    private ConversationView $view;
    private ConversationRuntime $runtime;

    protected function setUp(): void
    {
        $agent = new Agent();
        $history = new InMemoryChatHistory();
        $history->addMessage(new UserMessage('Earlier conversation'));
        $agent->setChatHistory($history);
        $this->terminal = new VirtualTerminal(rows: 30);
        $this->view = new ConversationView($this->terminal, 'Neuron AI', 'Conversation');
        $this->runtime = new ConversationRuntime($agent, $this->view);
        $this->runtime->synchronizeHistory();
    }

    public function testCompletionDisplaysAHistoryReplacedDirectlyOnTheAgent(): void
    {
        $replacement = new InMemoryChatHistory();
        $replacement->addMessage(new UserMessage('Replacement conversation'));

        $this->runCommand(static function (CommandAdapterInterface $adapter) use ($replacement): void {
            $adapter->agent()->setChatHistory($replacement);
        });

        $display = $this->display();
        self::assertStringContainsString('Replacement conversation', $display);
        self::assertStringNotContainsString('Earlier conversation', $display);
    }

    public function testMessagesAfterAHistoryChangeSurviveCompletionAndTheNextInvocation(): void
    {
        $this->runCommand(static function (CommandAdapterInterface $adapter): void {
            $adapter->agent()->setChatHistory(new InMemoryChatHistory());
            $adapter->notify('Session changed');
            $adapter->warn('A warning remains');
            $adapter->error('An expected error remains');
        });
        $this->runCommand(static function (CommandAdapterInterface $adapter): void {
        });

        $display = $this->display();
        self::assertStringContainsString('Session changed', $display);
        self::assertStringContainsString('A warning remains', $display);
        self::assertStringContainsString('An expected error remains', $display);
        self::assertStringNotContainsString('Earlier conversation', $display);
    }

    public function testAPromptAfterAHistoryChangeRemainsVisibleAtCompletion(): void
    {
        $this->runCommand(static function (CommandAdapterInterface $adapter): void {
            $adapter->agent()->setChatHistory(new InMemoryChatHistory());
            $adapter->promptAgent('Question in the new conversation');
        });

        $display = $this->display();
        self::assertStringContainsString('Question in the new conversation', $display);
        self::assertStringNotContainsString('Earlier conversation', $display);
    }

    /** @param Closure(CommandAdapterInterface<null>): void $run */
    private function runCommand(Closure $run): void
    {
        $command = new class($run) implements CommandInterface {
            /** @param Closure(CommandAdapterInterface<null>): void $run */
            public function __construct(private readonly Closure $run)
            {
            }

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
        $commands = new Commands($command);
        $storage = new InMemoryStorage();
        $commands->run('/probe', '', new TuiCommandAdapter(
            $this->runtime,
            $this->view,
            $commands,
            new SessionStore($storage, 'test-user'),
            new ConfigurationStore($storage, 'test-user'),
        ));
    }

    private function display(): string
    {
        $this->view->paintPendingChanges();

        return AnsiUtils::stripAnsiCodes($this->terminal->getOutput());
    }
}
