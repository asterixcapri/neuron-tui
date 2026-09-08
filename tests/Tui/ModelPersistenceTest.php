<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Tui;

use NeuronTui\Tests\Support\TestAgent;

use NeuronAI\Agent\Agent;
use NeuronInteraction\Command\ClearCommand;
use NeuronInteraction\Command\CommandControlsAdapterInterface;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\ResumeCommand;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\InputHistory\InputHistory;
use NeuronInteraction\Session\Session;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\FileStorage;
use NeuronTui\Tests\Support\ConfiguredAgent;
use NeuronTui\Tests\Support\ObservedCommand;
use NeuronTui\Tui;
use NeuronTuiDemo\ModelCommand;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class ModelPersistenceTest extends TestCase
{
    public function testActualModelSelectionSurvivesClearResumeAndFreshComposition(): void
    {
        $directory = sys_get_temp_dir() . '/neuron-model-' . bin2hex(random_bytes(6));
        try {
            $storage = new FileStorage($directory);
            $sessions = new SessionStore($storage, 'alice');
            $configurations = new ConfigurationStore($storage, 'alice');
            $configurations->create('agent', [
                'agent' => 'demo', 'model' => 'openai:gpt-5.6-sol', 'capability' => 'enabled', 'unrelated' => ['theme' => 'dark'],
            ]);
            $active = ConfiguredAgent::createAgent($configurations);
            $initialAgent = $active;
            $initial = $sessions->create();
            $active->setChatHistory($initial);
            $terminal = new VirtualTerminal(rows: 40);
            $inputs = new InputHistory($storage);
            $agents = [];
            $observe = static function (CommandControlsAdapterInterface $controls) use (&$active, &$agents): void {
                $active = $controls->agent();
                $agents[] = $active;
            };
            $commands = new Commands(array_map(
                static fn (CommandInterface $command): CommandInterface => new ObservedCommand($command, $observe),
                [new ModelCommand(), new ClearCommand(), new ResumeCommand()],
            ));
            EventLoop::queue(static fn () => $terminal->simulateInput("Initial question\r"));
            EventLoop::delay(0.15, static fn () => $terminal->simulateInput("/model\r"));
            EventLoop::delay(0.2, static function () use ($configurations, $terminal): void {
                $fresh = $configurations->read('agent');
                self::assertNotNull($fresh);
                $fresh->set('capability', 'updated');
                $configurations->write($fresh);
                $terminal->simulateInput("\x1b[B");
            });
            EventLoop::delay(0.24, static fn () => $terminal->simulateInput("\r"));
            EventLoop::delay(0.3, static fn () => $terminal->simulateInput("Continue with selected model\r"));
            EventLoop::delay(0.48, static fn () => $terminal->simulateInput("/clear\r"));
            EventLoop::delay(0.54, static fn () => $terminal->simulateInput("New conversation\r"));
            EventLoop::delay(0.72, static fn () => $terminal->simulateInput('/resume ' . $initial->getKey() . "\r"));
            EventLoop::delay(0.78, static fn () => $terminal->simulateInput("Back in the original\r"));
            EventLoop::delay(0.96, static fn () => $terminal->simulateInput("\x03"));

            Tui::make(TestAgent::registryForInitialAgent($active),
            'test',
            $terminal,
            $commands,
            $sessions,
            $inputs,
            $configurations)->run();

            self::assertCount(4, $agents); // Selection request, selected model, Clear, Resume.
            self::assertSame($initialAgent, $agents[0]);
            self::assertNotSame($initialAgent, $agents[1]);
            self::assertSame($initial, $agents[1]->getChatHistory());
            self::assertNotSame($agents[1], $agents[2]);
            self::assertNotSame($agents[2], $agents[3]);
            self::assertNotSame($initial->getKey(), $agents[2]->getThreadId());
            self::assertSame($initial->getKey(), $active->getThreadId());
            self::assertSame('openai:gpt-5.6-sol / enabled', $initial->getMessages()[1]->getContent());
            self::assertSame('openai:gpt-5.6-terra / updated', $initial->getMessages()[3]->getContent());
            self::assertSame('openai:gpt-5.6-terra / updated', $agents[2]->getChatHistory()->getMessages()[1]->getContent());
            self::assertSame('openai:gpt-5.6-terra / updated', $active->getChatHistory()->getMessages()[5]->getContent());
            self::assertCount(2, $sessions->summaries());
            self::assertContains('/model', $inputs->entries());
            self::assertContains('/clear', $inputs->entries());
            self::assertStringContainsString('Model changed to openai:gpt-5.6-terra.', AnsiUtils::stripAnsiCodes($terminal->getOutput()));

            // A new composition reopens both stores and explicitly chooses its startup Session.
            $reopenedStorage = new FileStorage($directory);
            $reopenedConfigurations = new ConfigurationStore($reopenedStorage, 'alice');
            $saved = $reopenedConfigurations->read('agent');
            self::assertNotNull($saved);
            self::assertSame(['agent' => 'demo', 'model' => 'openai:gpt-5.6-terra', 'capability' => 'updated', 'unrelated' => ['theme' => 'dark']], $saved->all());
            $reopenedSessions = new SessionStore($reopenedStorage, 'alice');
            $history = $reopenedSessions->read($initial->getKey());
            self::assertInstanceOf(Session::class, $history);
            $freshAgent = ConfiguredAgent::createAgent($reopenedConfigurations);
            $freshAgent->setChatHistory($history);
            $freshTerminal = new VirtualTerminal(rows: 40);
            EventLoop::queue(static fn () => $freshTerminal->simulateInput("After restart\r"));
            EventLoop::delay(0.18, static fn () => $freshTerminal->simulateInput("\x03"));
            Tui::make(TestAgent::registryForInitialAgent($freshAgent),
            'test',
            $freshTerminal,
            sessionStore: $reopenedSessions,
            configurationStore: $reopenedConfigurations)->run();
            self::assertSame($initial->getKey(), $freshAgent->getThreadId());
            self::assertCount(8, $history->getMessages());
            self::assertSame('openai:gpt-5.6-terra / updated', $history->getMessages()[7]->getContent());
            self::assertStringContainsString('openai:gpt-5.6-terra / updated', AnsiUtils::stripAnsiCodes($freshTerminal->getOutput()));
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
}
