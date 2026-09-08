<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Command;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronInteraction\Agent\AgentFactoryRegistry;
use NeuronInteraction\Command\CommandArguments;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\CommandControlsAdapterInterface;
use NeuronInteraction\Command\LeaveCommand;
use NeuronInteraction\Configuration\Configuration;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteraction\Storage\StorageInterface;
use NeuronInteraction\Storage\StoredDocument;
use NeuronTui\Conversation\ConversationRuntime;
use NeuronTui\Conversation\TuiAdapter;
use NeuronTui\View\ConversationView;
use NeuronTui\Tests\Support\ObservedCommand;
use NeuronTui\Tui;
use Revolt\EventLoop;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use NeuronTuiDemo\ModelCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class ModelCommandTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function failures(): iterable
    {
        foreach (['invalid', 'missing', 'unknown', 'factory', 'history', 'save'] as $failure) {
            yield $failure => [$failure];
        }
    }

    #[DataProvider('failures')]
    public function testFailedPreparationLeavesCurrentAgentUsableAndSettingsIntact(string $failure): void
    {
        $storage = new InMemoryStorage();
        $store = new ConfigurationStore($storage, 'test');
        $values = ['agent' => 'demo', 'model' => 'openai:gpt-5.4-nano', 'capability' => 'search'];
        if ($failure !== 'missing') {
            $store->create('global', $values);
        }
        if ($failure === 'save') {
            $failing = $this->createMock(StorageInterface::class);
            $failing->method('read')->willReturnCallback(
                static fn (string $namespace, string $key): ?StoredDocument => $storage->read($namespace, $key),
            );
            $failing->expects(self::once())->method('write')->willThrowException(new RuntimeException('save failed'));
            $store = new ConfigurationStore($failing, 'test');
        }
        $registry = new AgentFactoryRegistry();
        if ($failure !== 'unknown') {
            $registry->register('demo', static function (Configuration $configuration) use ($failure): Agent {
                if ($failure === 'factory') {
                    throw new RuntimeException('factory failed');
                }
                if ($failure === 'history') {
                    return new class extends Agent {
                        public function setChatHistory(ChatHistoryInterface $chatHistory): self
                        {
                            throw new RuntimeException('history failed');
                        }
                    };
                }

                return new Agent();
            });
        }
        $original = new Agent();
        $original->setAiProvider(new FakeAIProvider(new AssistantMessage('Still available')));
        $sessions = new SessionStore($storage, 'test');
        $history = $sessions->create();
        $original->setChatHistory($history);
        $terminal = new VirtualTerminal(rows: 40);
        $activeAtExit = null;
        $commands = new Commands([
            new ModelCommand(),
            new ObservedCommand(new LeaveCommand(), static function (CommandControlsAdapterInterface $adapter) use (&$activeAtExit): void {
                $activeAtExit = $adapter->agent();
            }),
        ]);
        $model = $failure === 'invalid' ? 'openai:unlisted' : 'openai:gpt-5.6-sol';
        EventLoop::queue(static fn () => $terminal->simulateInput('/model ' . $model . "\r"));
        EventLoop::delay(0.06, static fn () => $terminal->simulateInput("Are you still available?\r"));
        EventLoop::delay(0.24, static fn () => $terminal->simulateInput("/exit\r"));

        Tui::make($original, $terminal, $commands, $sessions,
            agentFactoryRegistry: $registry, configurationStore: $store,
        )->run();

        self::assertSame($original, $activeAtExit);
        self::assertSame($history, $activeAtExit->getChatHistory());
        self::assertSame($failure === 'missing' ? null : $values, $store->read('global')?->all());
        self::assertSame('Still available', $history->getLastMessage()->getContent());
        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString(match ($failure) {
            'invalid' => 'Unknown demo model: openai:unlisted',
            'missing' => 'General configuration "global" is missing.',
            'unknown' => 'Unknown Agent factory: demo',
            default => $failure . ' failed',
        }, $display);
        self::assertStringContainsString('Still available', $display);
    }

    public function testSavesOnlyTheModelAfterPreparingTheSameHistory(): void
    {
        $storage = new InMemoryStorage();
        $store = new ConfigurationStore($storage, 'test');
        $store->create('global', ['agent' => 'custom', 'model' => 'openai:gpt-5.4-nano', 'capability' => ['search' => true]]);
        $original = new Agent();
        $sessions = new SessionStore($storage, 'test');
        $original->setChatHistory($sessions->create());
        $registry = new AgentFactoryRegistry();
        $candidate = new Agent();
        $registry->register('custom', static function (Configuration $configuration) use ($store, $candidate): Agent {
            self::assertSame('openai:gpt-5.6-sol', $configuration->get('model'));
            self::assertSame('openai:gpt-5.4-nano', $store->read('global')?->get('model'));
            self::assertSame(['search' => true], $configuration->get('capability'));
            $configuration->set('capability', 'factory-local');

            return $candidate;
        });
        $view = new ConversationView(new VirtualTerminal(), 'Test', 'Models');
        $adapter = new TuiAdapter(new ConversationRuntime($original, $view), $view, new Commands(), $sessions, $registry, $store);

        (new ModelCommand())->run($adapter, new CommandArguments('openai:gpt-5.6-sol'));

        self::assertSame($candidate, $adapter->agent());
        self::assertSame($original->getChatHistory(), $candidate->getChatHistory());
        self::assertSame([
            'agent' => 'custom',
            'model' => 'openai:gpt-5.6-sol',
            'capability' => ['search' => true],
        ], $store->read('global')?->all());
    }
}
