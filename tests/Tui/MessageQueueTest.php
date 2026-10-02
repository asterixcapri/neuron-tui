<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Tui;

use Generator;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Testing\FakeAIProvider;
use NeuronInteraction\Conversation\ConversationRuntime;
use NeuronInteraction\InputHistory\InputHistory;
use NeuronInteraction\Message\UserMessageProcessorInterface;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronTui\Tui;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use RuntimeException;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

use function Amp\delay;
use function array_map;

final class MessageQueueTest extends TestCase
{
    public function testPendingInputsWaitForPreparationAndRejectionPreservesANewerDraftAndAdvances(): void
    {
        $provider = new class (new AssistantMessage('First reply'), new AssistantMessage('Next reply')) extends FakeAIProvider {
            protected function streamChunks(Message $response): Generator
            {
                delay(0.12);
                yield new TextChunk('reply', $response->getContent() ?? '');
                return new ProviderResponse(message: $response);
            }
        };
        $processor = new class implements UserMessageProcessorInterface {
            /** @var list<string|null> */
            public array $prepared = [];
            public function forAgent(UserMessage $input): UserMessage
            {
                $this->prepared[] = $input->getContent();
                if ($input->getContent() === 'Invalid') {
                    throw new RuntimeException('Invalid queued input');
                }
                return clone $input;
            }
            public function forDisplay(UserMessage $input): UserMessage
            {
                return clone $input;
            }
        };
        $inputs = new InputHistory(new InMemoryStorage());
        $terminal = new VirtualTerminal(rows: 40);
        $runtime = new ConversationRuntime((new Agent())->setAiProvider($provider), userMessageProcessors: $processor);
        EventLoop::queue(static fn() => $terminal->simulateInput("First\r"));
        EventLoop::delay(0.03, static fn() => $terminal->simulateInput("Invalid\rNext\rNewer draft"));
        $beforeCompletion = null;
        EventLoop::delay(0.07, static function () use ($processor, &$beforeCompletion): void {
            $beforeCompletion = $processor->prepared;
        });
        EventLoop::delay(0.4, static fn() => $terminal->simulateInput("\x03"));
        Tui::make($runtime, $terminal, inputHistory: $inputs)->run();

        self::assertSame(['First'], $beforeCompletion);
        self::assertSame(['First', 'Invalid', 'Next'], $processor->prepared);
        self::assertCount(2, $provider->getRecorded());
        self::assertSame('Next', $provider->getRecorded()[1]->messages[2]->getContent());
        self::assertSame(['First', 'Invalid', 'Next'], array_map(static fn(UserMessage $message) => $message->getContent(), $inputs->entries()));
        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString('↳ Invalid', $display);
        self::assertStringContainsString('Invalid queued input', $display);
        self::assertStringContainsString('Next reply', $display);
        self::assertStringContainsString('❯ Newer draft', $display);
    }

    public function testRejectedQueuedInputReturnsToAnEmptyComposer(): void
    {
        $provider = new class (new AssistantMessage('Reply')) extends FakeAIProvider {
            protected function streamChunks(Message $response): Generator
            {
                delay(0.12);
                yield new TextChunk('reply', 'Reply');
                return new ProviderResponse(message: $response);
            }
        };
        $processor = new class implements UserMessageProcessorInterface {
            public function forAgent(UserMessage $input): UserMessage
            {
                if ($input->getContent() === 'Recover me') {
                    throw new RuntimeException('Preparation failed');
                }
                return clone $input;
            }
            public function forDisplay(UserMessage $input): UserMessage
            {
                return clone $input;
            }
        };
        $terminal = new VirtualTerminal(rows: 30);
        $runtime = new ConversationRuntime((new Agent())->setAiProvider($provider), userMessageProcessors: $processor);
        EventLoop::queue(static fn() => $terminal->simulateInput("First\r"));
        EventLoop::delay(0.03, static fn() => $terminal->simulateInput("Recover me\r"));
        EventLoop::delay(0.25, static fn() => $terminal->simulateInput("\x03"));
        Tui::make($runtime, $terminal)->run();
        self::assertCount(1, $provider->getRecorded());
        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString('Preparation failed', $display);
        self::assertStringContainsString('❯ Recover me', $display);
    }
}
