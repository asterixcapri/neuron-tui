<?php

declare(strict_types=1);

namespace NeuronTuiDemo\Tests;

use Closure;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronInteraction\Command\CommandAdapterInterface;
use NeuronInteraction\Command\CommandArguments;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronTui\Tui;
use NeuronTuiDemo\DemoAgent;
use NeuronTuiDemo\ModelCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class ModelCommandTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function invocations(): iterable
    {
        yield 'picker continuation' => [true];
        yield 'explicit model' => [false];
    }

    #[DataProvider('invocations')]
    public function testModelChangePreservesTheSessionWithoutRequestingAProvider(bool $picker): void
    {
        $storage = new InMemoryStorage();
        $sessions = new SessionStore($storage, 'demo-user');
        $history = $sessions->create();
        $history->addMessage(new UserMessage('Existing demo conversation'));
        $history->addMessage(new AssistantMessage('Existing answer'));
        $original = DemoAgent::make();
        $original->setChatHistory($history);
        $provider = new FakeAIProvider();
        $original->setAiProvider($provider);
        $selected = null;
        $inspect = new class(
            static function (Agent $agent) use (&$selected): void {
                $selected = $agent;
            },
        ) implements CommandInterface {
            /** @param Closure(Agent): void $capture */
            public function __construct(private readonly Closure $capture) {}

            public function name(): string
            {
                return '/inspect';
            }

            public function describe(): string
            {
                return 'Inspect the selected demo Agent.';
            }

            /** @param CommandAdapterInterface<mixed> $adapter */
            public function run(CommandAdapterInterface $adapter, CommandArguments $arguments): void
            {
                ($this->capture)($adapter->agent());
                $adapter->stop();
            }
        };
        $terminal = new VirtualTerminal(rows: 30);
        EventLoop::queue(static fn () => $terminal->simulateInput(
            $picker ? "/model\r" : "/model anthropic:claude-sonnet-5\r",
        ));
        if ($picker) {
            EventLoop::delay(0.05, static fn () => $terminal->simulateInput("\r"));
        }
        EventLoop::delay(0.1, static fn () => $terminal->simulateInput("/inspect\r"));

        Tui::make($original, $terminal, new Commands([new ModelCommand(), $inspect]), $sessions)->run();

        self::assertInstanceOf(DemoAgent::class, $selected);
        self::assertNotSame($original, $selected);
        self::assertSame($history, $selected->getChatHistory());
        self::assertCount(2, $selected->getChatHistory()->getMessages());
        self::assertStringContainsString(
            'Model changed to ' . ($picker ? 'openai:gpt-5.6-sol' : 'anthropic:claude-sonnet-5'),
            AnsiUtils::stripAnsiCodes($terminal->getOutput()),
        );
        $selected->getChatHistory()->addMessage(new UserMessage('After the switch'));
        self::assertCount(3, $sessions->read($history->getKey())?->getMessages() ?? []);
        $provider->assertCallCount(0);
    }
}
