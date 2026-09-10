<?php

declare(strict_types=1);

namespace NeuronTuiDemo\Tests;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronAI\Testing\FakeAIProvider;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronInteraction\Storage\StorageInterface;
use NeuronInteraction\Storage\StoredDocument;
use RuntimeException;
use PHPUnit\Framework\Attributes\DataProvider;
use NeuronTui\Tui;
use NeuronTuiDemo\ModelCommand;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class ModelCommandTest extends TestCase
{
    /** @return iterable<string, array{bool, string}> */
    public static function modelInputs(): iterable
    {
        yield 'argument' => [false, 'openai:gpt-5.4-nano'];
        yield 'selection' => [true, 'openai:gpt-5.6-sol'];
    }

    #[DataProvider('modelInputs')]
    public function testModelIsSavedWithoutReplacingTheAgentOrHistory(bool $selection, string $model): void
    {
        $previousKey = $_ENV['OPENAI_API_KEY'] ?? null;
        $_ENV['OPENAI_API_KEY'] = 'construction-only-test-key';
        try {
            $storage = new InMemoryStorage();
            $store = new ConfigurationStore($storage, 'demo-user');
            $store->write('theme', 'dark');
            $agent = new Agent();
            $agent->setAiProvider(new FakeAIProvider());
            $history = $agent->getChatHistory();
            $history->addMessage(new UserMessage('Keep this conversation'));
            $terminal = new VirtualTerminal();
            $beforeSelection = null;
            EventLoop::queue(static fn () => $terminal->simulateInput($selection ? "/model\r" : "/model {$model}\r"));
            if ($selection) {
                EventLoop::delay(0.04, static function () use ($terminal, $store, &$beforeSelection): void {
                    $beforeSelection = $store->entries();
                    $terminal->simulateInput("\r");
                });
            }
            EventLoop::delay(0.08, static fn () => $terminal->simulateInput("\x03"));

            Tui::make($agent, $terminal, new Commands(new ModelCommand()), configurationStore: $store)->run();

            self::assertSame($model, (new ConfigurationStore($storage, 'demo-user'))->read('model'));
            self::assertSame('dark', $store->read('theme'));
            if ($selection) {
                self::assertSame(['theme' => 'dark'], $beforeSelection);
            }
            self::assertInstanceOf(OpenAIResponses::class, $agent->resolveProvider());
            self::assertSame($history, $agent->getChatHistory());
            self::assertSame('Keep this conversation', $history->getMessages()[0]->getContent());
            self::assertStringContainsString("Model changed to {$model}.", AnsiUtils::stripAnsiCodes($terminal->getOutput()));
        } finally {
            if ($previousKey === null) {
                unset($_ENV['OPENAI_API_KEY']);
            } else {
                $_ENV['OPENAI_API_KEY'] = $previousKey;
            }
        }
    }

    public function testCancellingSelectionPreservesTheSavedModelAndProvider(): void
    {
        $store = new ConfigurationStore(new InMemoryStorage(), 'demo-user');
        $store->write('model', 'previous');
        $agent = new Agent();
        $provider = new FakeAIProvider();
        $agent->setAiProvider($provider);
        $terminal = new VirtualTerminal();
        EventLoop::queue(static fn () => $terminal->simulateInput("/model\r"));
        EventLoop::delay(0.04, static fn () => $terminal->simulateInput("\x1b"));
        EventLoop::delay(0.08, static fn () => $terminal->simulateInput("\x03"));

        Tui::make($agent, $terminal, new Commands(new ModelCommand()), configurationStore: $store)->run();

        self::assertSame(['model' => 'previous'], $store->entries());
        self::assertSame($provider, $agent->resolveProvider());
        self::assertStringNotContainsString('Model changed to', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testProviderConstructionFailureIsDisplayedWithoutSavingOrSuccess(): void
    {
        $store = new ConfigurationStore(new InMemoryStorage(), 'demo-user');
        $store->write('model', 'previous');
        $agent = new Agent();
        $provider = new FakeAIProvider();
        $agent->setAiProvider($provider);
        $terminal = new VirtualTerminal();
        EventLoop::queue(static fn () => $terminal->simulateInput("/model unknown:model\r"));
        EventLoop::delay(0.08, static fn () => $terminal->simulateInput("\x03"));

        Tui::make($agent, $terminal, new Commands(new ModelCommand()), configurationStore: $store)->run();

        self::assertSame('previous', $store->read('model'));
        self::assertSame($provider, $agent->resolveProvider());
        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString('Unknown provider: unknown.', $display);
        self::assertStringNotContainsString('Model changed to', $display);
    }

    public function testPersistenceFailureIsDisplayedWithoutSuccess(): void
    {
        $previousKey = $_ENV['OPENAI_API_KEY'] ?? null;
        $_ENV['OPENAI_API_KEY'] = 'construction-only-test-key';
        try {
            $storage = $this->createStub(StorageInterface::class);
            $storage->method('read')->willReturn(new StoredDocument('stored', ['model' => 'previous'], ['userId' => 'demo-user']));
            $storage->method('write')->willThrowException(new RuntimeException('Preferences unavailable'));
            $store = new ConfigurationStore($storage, 'demo-user');
            $agent = new Agent();
            $history = $agent->getChatHistory();
            $terminal = new VirtualTerminal();
            EventLoop::queue(static fn () => $terminal->simulateInput("/model openai:gpt-5.4-nano\r"));
            EventLoop::delay(0.08, static fn () => $terminal->simulateInput("\x03"));

            Tui::make($agent, $terminal, new Commands(new ModelCommand()), configurationStore: $store)->run();

            self::assertSame('previous', $store->read('model'));
            self::assertSame($history, $agent->getChatHistory());
            $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
            self::assertStringContainsString('Preferences unavailable', $display);
            self::assertStringNotContainsString('Model changed to', $display);
        } finally {
            if ($previousKey === null) {
                unset($_ENV['OPENAI_API_KEY']);
            } else {
                $_ENV['OPENAI_API_KEY'] = $previousKey;
            }
        }
    }
}
