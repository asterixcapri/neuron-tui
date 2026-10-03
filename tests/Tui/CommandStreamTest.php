<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Tui;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronInteraction\Command\CommandContext;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronTui\Tui;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Component\Tui\Terminal\ScreenBuffer;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

use function strpos;

final class CommandStreamTest extends TestCase
{
    public function testMultiplePromptsAndNotificationsShareTheirSubmissionStream(): void
    {
        $command = new class implements CommandInterface {
            public function name(): string
            {
                return '/sequence';
            }
            public function describe(): string
            {
                return 'Two ordered prompts';
            }
            public function run(CommandContext $context, string $value): void
            {
                $context->notify('Before the first answer');
                $context->promptAgent(new UserMessage('First generated prompt'));
                $context->notify('Between the answers');
                $context->promptAgent(new UserMessage('Second generated prompt'));
                $context->notify('After the second answer');
            }
        };
        $provider = new FakeAIProvider(new AssistantMessage('First answer'), new AssistantMessage('Second answer'));
        $terminal = new VirtualTerminal(rows: 35);
        EventLoop::queue(static fn() => $terminal->simulateInput("/sequence\r"));
        EventLoop::delay(0.2, static fn() => $terminal->simulateInput("\x03"));
        Tui::make((new Agent())->setAiProvider($provider))
            ->setTerminal($terminal)
            ->setCommands(new Commands($command))
            ->run();

        $screen = new ScreenBuffer($terminal->getColumns(), $terminal->getRows());
        $screen->write($terminal->getOutput());
        $display = $screen->getScreen();
        $last = -1;
        foreach (['Before the first answer', 'First answer', 'Between the answers', 'Second answer', 'After the second answer'] as $text) {
            $position = strpos($display, $text);
            self::assertNotFalse($position);
            self::assertGreaterThan($last, $position);
            $last = $position;
        }
        self::assertStringNotContainsString('❯ First generated prompt', $display);
        self::assertStringNotContainsString('❯ Second generated prompt', $display);
        self::assertStringNotContainsString('Empty response.', $display);
        $provider->assertCallCount(2);
        self::assertSame('First generated prompt', $provider->getRecorded()[0]->messages[0]->getContent());
        self::assertSame('Second generated prompt', $provider->getRecorded()[1]->messages[2]->getContent());
    }

    public function testCommandWithoutRequestsDoesNotRenderAnAgentResponseOrError(): void
    {
        $command = new class implements CommandInterface {
            public function name(): string
            {
                return '/noop';
            }
            public function describe(): string
            {
                return 'No requests';
            }
            public function run(CommandContext $context, string $value): void {}
        };
        $provider = new FakeAIProvider();
        $terminal = new VirtualTerminal();
        EventLoop::queue(static fn() => $terminal->simulateInput("/noop\r"));
        EventLoop::delay(0.08, static fn() => $terminal->simulateInput("\x03"));
        Tui::make((new Agent())->setAiProvider($provider))
            ->setTerminal($terminal)
            ->setCommands(new Commands($command))
            ->run();
        $screen = new ScreenBuffer($terminal->getColumns(), $terminal->getRows());
        $screen->write($terminal->getOutput());
        $display = $screen->getScreen();
        self::assertStringNotContainsString('Empty response.', $display);
        self::assertStringNotContainsString('Exception', $display);
        self::assertStringNotContainsString('●', $display);
        $provider->assertNothingSent();
    }
}
