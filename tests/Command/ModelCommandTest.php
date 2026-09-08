<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Command;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronInteraction\Agent\AgentFactoryRegistry;
use NeuronInteraction\Agent\ConfiguredAgentInterface;
use NeuronInteraction\Command\CommandArguments;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteraction\Storage\StorageInterface;
use NeuronInteraction\Storage\StoredDocument;
use NeuronTui\Conversation\ConversationRuntime;
use NeuronTui\Conversation\TuiAdapter;
use NeuronTui\View\ConversationView;
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
        foreach (['invalid', 'missing', 'unknown', 'factory', 'history', 'write'] as $failure) {
            yield $failure => [$failure];
        }
    }

    #[DataProvider('failures')]
    public function testFailureKeepsCurrentAgentUsableWithoutRollingBackSavedSettings(string $failure): void
    {
        $storage = new InMemoryStorage();
        $store = new ConfigurationStore($storage, 'test');
        $values = ['model' => 'openai:gpt-5.4-nano', 'capability' => 'search'];
        if ($failure !== 'missing') {
            $store->create('agent', $values);
        }
        if ($failure === 'write') {
            $failing = $this->createMock(StorageInterface::class);
            $failing->method('read')->willReturnCallback(
                static fn (string $namespace, string $key): ?StoredDocument => $storage->read($namespace, $key),
            );
            $failing->expects(self::once())->method('write')->willThrowException(new RuntimeException('write failed'));
            $store = new ConfigurationStore($failing, 'test');
        }
        $registry = new AgentFactoryRegistry();
        if ($failure !== 'unknown') {
            $registry->register('demo', ModelAgent::class);
        }
        ModelAgent::$failure = $failure;
        $original = new Agent();
        $original->setAiProvider(new FakeAIProvider(new AssistantMessage('Still available')));
        $sessions = new SessionStore($storage, 'test');
        $history = $sessions->create();
        $original->setChatHistory($history);
        $view = new ConversationView(new VirtualTerminal(), 'Test', 'Models');
        $commands = new Commands(new ModelCommand());
        $controls = new TuiAdapter(new ConversationRuntime($original, $view), $view, $commands, $sessions, $registry, $store, 'demo');
        $model = $failure === 'invalid' ? 'openai:unlisted' : 'openai:gpt-5.6-sol';

        $commands->run('/model', new CommandArguments($model), $controls);

        self::assertSame($original, $controls->agent());
        self::assertSame($history, $controls->agent()->getChatHistory());
        if (in_array($failure, ['unknown', 'factory', 'history'], true)) {
            $values['model'] = 'openai:gpt-5.6-sol';
        }
        self::assertSame($failure === 'missing' ? null : $values, $store->read('agent')?->all());
        $controls->agent()->chat(new UserMessage('Are you still available?'));
        self::assertSame('Still available', $history->getLastMessage()->getContent());
    }

    public function testFactoryReadsSavedModelAndReplacementRetainsTheSameHistory(): void
    {
        $storage = new InMemoryStorage();
        $store = new ConfigurationStore($storage, 'test');
        $store->create('agent', ['model' => 'openai:gpt-5.4-nano', 'capability' => ['search' => true]]);
        $original = new Agent();
        $sessions = new SessionStore($storage, 'test');
        $original->setChatHistory($sessions->create());
        $registry = new AgentFactoryRegistry();
        $registry->register('custom', ModelAgent::class);
        ModelAgent::$failure = '';
        $view = new ConversationView(new VirtualTerminal(), 'Test', 'Models');
        $controls = new TuiAdapter(new ConversationRuntime($original, $view), $view, new Commands(), $sessions, $registry, $store, 'custom');

        (new ModelCommand())->run($controls, new CommandArguments('openai:gpt-5.6-sol'));

        $candidate = $controls->agent();
        self::assertInstanceOf(ModelAgent::class, $candidate);
        self::assertSame('openai:gpt-5.6-sol', $candidate->model);
        self::assertSame($original->getChatHistory(), $candidate->getChatHistory());
        self::assertSame([
            'model' => 'openai:gpt-5.6-sol',
            'capability' => ['search' => true],
        ], $store->read('agent')?->all());
    }
}

final class ModelAgent extends Agent implements ConfiguredAgentInterface
{
    public static string $failure = '';
    public mixed $model;

    public static function createAgent(ConfigurationStore $configurationStore): static
    {
        if (self::$failure === 'factory') {
            throw new RuntimeException('factory failed');
        }
        $agent = new static();
        $configuration = $configurationStore->read('agent');
        $agent->model = $configuration?->get('model');
        // Mutating a read document alone does not write back into the store.
        $configuration?->set('capability', 'factory-local');
        return $agent;
    }

    public function setChatHistory(ChatHistoryInterface $chatHistory): self
    {
        if (self::$failure === 'history') {
            throw new RuntimeException('history failed');
        }
        parent::setChatHistory($chatHistory);
        return $this;
    }
}
