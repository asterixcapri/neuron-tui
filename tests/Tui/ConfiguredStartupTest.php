<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Tui;

use InvalidArgumentException;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Agent\AgentFactoryRegistry;
use NeuronInteraction\Command\CommandArguments;
use NeuronInteraction\Command\CommandControlsAdapterInterface;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\ClearCommand;
use NeuronInteraction\Command\ResumeCommand;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Session\Session;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\FileStorage;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronTui\Tui;
use NeuronTuiDemo\DemoAgent;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use RuntimeException;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class ConfiguredStartupTest extends TestCase
{
    public function testDemoInitialSessionIsStoredAndResumableWithoutExtraFactorySessions(): void
    {
        $directory = sys_get_temp_dir() . '/neuron-demo-startup-' . bin2hex(random_bytes(6));
        try {
            $storage = new FileStorage($directory);
            $store = new ConfigurationStore($storage, 'alice');
            $store->create('agent', [
                'model' => 'openai:gpt-5.4-nano',
                'sessionStoragePath' => $directory,
            ]);
            $registry = new AgentFactoryRegistry();
            $registry->register('demo', DemoAgent::class);
            $sessions = new SessionStore($storage, 'alice');
            $command = new class implements CommandInterface {
                public bool $completed = false;

                public function name(): string { return '/exercise'; }
                public function describe(): string { return 'Exercise the initial demo Session.'; }

                /** @param CommandControlsAdapterInterface<mixed> $controls */
                public function run(CommandControlsAdapterInterface $controls, CommandArguments $arguments): void
                {
                    TestCase::assertInstanceOf(DemoAgent::class, $controls->agent());
                    $initialAgent = $controls->agent();
                    $initial = $initialAgent->getChatHistory();
                    TestCase::assertInstanceOf(Session::class, $initial);
                    TestCase::assertSame('alice', $initial->getUserId());
                    $initial->addMessage(new UserMessage('First demo conversation'));
                    TestCase::assertSame('First demo conversation', $controls->sessionStore()->read($initial->getKey())?->getMessages()[0]->getContent());

                    (new ClearCommand())->run($controls, new CommandArguments());
                    TestCase::assertNotSame($initialAgent, $controls->agent());
                    TestCase::assertNotSame($initial->getKey(), $controls->agent()->getThreadId());
                    (new ResumeCommand())->run($controls, new CommandArguments($initial->getKey()));
                    TestCase::assertSame($initial->getKey(), $controls->agent()->getThreadId());
                    TestCase::assertSame('First demo conversation', $controls->agent()->getChatHistory()->getMessages()[0]->getContent());
                    $this->completed = true;
                    $controls->stop();
                }
            };
            $terminal = new VirtualTerminal();
            $tui = Tui::make(
                agentFactoryRegistry: $registry,
                initialAgentIdentifier: 'demo',
                configurationStore: $store,
                sessionStore: $sessions,
                terminal: $terminal,
                commands: new Commands($command),
            );
            self::assertSame([], iterator_to_array($storage->entries('sessions')));
            EventLoop::queue(static fn () => $terminal->simulateInput("/exercise\r"));
            // Also stop on a failure, so a failed assertion cannot hang the suite.
            $timeout = EventLoop::delay(1, static fn () => $terminal->simulateInput("\x03"));
            try {
                $tui->run();
            } finally {
                EventLoop::cancel($timeout);
            }
            self::assertTrue($command->completed);
            self::assertCount(2, iterator_to_array($storage->entries('sessions')));
            self::assertSame('First demo conversation', (new SessionStore(new FileStorage($directory), 'alice'))->summaries()[0]->title);
        } finally {
            foreach (glob($directory . '/*/*') ?: [] as $path) {
                unlink($path);
            }
            foreach (glob($directory . '/*', GLOB_ONLYDIR) ?: [] as $path) {
                rmdir($path);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function testUnknownInitialIdentifierFailsBeforeOpeningTerminal(): void
    {
        $terminal = new VirtualTerminal();
        try {
            Tui::make(new AgentFactoryRegistry(), 'unknown', terminal: $terminal);
            self::fail('Unknown initial Agent accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Unknown Agent factory: unknown', $exception->getMessage());
        }
        self::assertSame('', $terminal->getOutput());
    }

    public function testInitialFactoryReceivesTheConfigurationStore(): void
    {
        $registry = new AgentFactoryRegistry();
        $registry->register('demo', DemoAgent::class);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Agent configuration "agent" is missing.');
        new Tui($registry, 'demo', configurationStore: new ConfigurationStore(new InMemoryStorage(), 'alice'));
    }
}
