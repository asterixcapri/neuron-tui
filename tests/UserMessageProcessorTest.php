<?php

declare(strict_types=1);

namespace NeuronTui\Tests;

use Generator;
use LogicException;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronInteraction\Command\CommandAdapterInterface;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\InputHistory\InputHistory;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronTui\Tui;
use NeuronTui\UserMessageProcessorInterface;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use RuntimeException;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class UserMessageProcessorTest extends TestCase
{
    public function testSingleAndArrayRegistrationsComposeWithoutChangingInputHistory(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Reply.'));
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $terminal = new VirtualTerminal(rows: 30);
        $inputHistory = new InputHistory(new InMemoryStorage());
        $tui = Tui::make($agent, $terminal, inputHistory: $inputHistory);
        self::assertSame($tui, $tui->addUserMessageProcessor(new EnvelopeProcessor('A')));
        self::assertSame($tui, $tui->addUserMessageProcessor([
            new EnvelopeProcessor('B'),
            new EnvelopeProcessor('C'),
        ]));

        EventLoop::queue(static fn () => $terminal->simulateInput("Hello\r"));
        EventLoop::delay(0.15, static fn () => $terminal->simulateInput("\x03"));
        $tui->run();

        self::assertSame('C[B[A[Hello]]]', $provider->getRecorded()[0]->messages[0]->getContent());
        self::assertSame('C[B[A[Hello]]]', $agent->getChatHistory()->getMessages()[0]->getContent());
        self::assertSame('Hello', $inputHistory->older());
        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString('❯ Hello', $display);
        self::assertStringNotContainsString('C[B[A[Hello]]]', $display);
    }

    public function testLoadedUserMessagesUseTheSamePresentationButAssistantMessagesDoNot(): void
    {
        $agent = new Agent();
        $agent->getChatHistory()->addMessage(new UserMessage('B[A[Earlier]]'));
        $agent->getChatHistory()->addMessage(new AssistantMessage('B[A[Reply]]'));
        $terminal = new VirtualTerminal(rows: 30);
        EventLoop::delay(0.05, static fn () => $terminal->simulateInput("\x03"));

        Tui::make($agent, $terminal)->addUserMessageProcessor([
            new EnvelopeProcessor('A'),
            new EnvelopeProcessor('B'),
        ])->run();

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString('❯ Earlier', $display);
        self::assertStringContainsString('B[A[Reply]]', $display);
        self::assertSame('B[A[Earlier]]', $agent->getChatHistory()->getMessages()[0]->getContent());
    }

    public function testCommandsBypassPreparationIncludingThePromptsTheyProduce(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Done.'));
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $command = new class implements CommandInterface {
            public function name(): string
            {
                return '/prepared';
            }
            public function describe(): string
            {
                return 'Send a prepared prompt.';
            }
            public function run(CommandAdapterInterface $adapter, string $value): void
            {
                $adapter->promptAgent('A[Command prompt]');
            }
        };
        $terminal = new VirtualTerminal(rows: 30);
        EventLoop::queue(static fn () => $terminal->simulateInput("/prepared\r"));
        EventLoop::delay(0.15, static fn () => $terminal->simulateInput("\x03"));

        Tui::make($agent, $terminal, commands: new Commands($command))
            ->addUserMessageProcessor(new EnvelopeProcessor('A'))->run();

        self::assertSame('A[Command prompt]', $provider->getRecorded()[0]->messages[0]->getContent());
        self::assertStringContainsString('❯ Command prompt', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testPreparationFailureLeavesTheDraftAndDoesNotContactTheProvider(): void
    {
        $provider = new FakeAIProvider();
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $processor = new class implements UserMessageProcessorInterface {
            public function forAgent(string $input): string
            {
                throw new RuntimeException('Cannot prepare message.');
            }
            public function forDisplay(string $content): string
            {
                return $content;
            }
        };
        $terminal = new VirtualTerminal(rows: 30);
        EventLoop::queue(static fn () => $terminal->simulateInput("Keep my draft\r"));
        EventLoop::delay(0.1, static fn () => $terminal->simulateInput("\x03"));

        Tui::make($agent, $terminal)->addUserMessageProcessor($processor)->run();

        self::assertSame([], $provider->getRecorded());
        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString('Cannot prepare message.', $display);
        self::assertStringContainsString('❯ Keep my draft', $display);
    }

    public function testQueuedMessagesArePreparedOnceAndDisplayedWithoutTheirEnvelope(): void
    {
        $provider = new class(new AssistantMessage('One.'), new AssistantMessage('Two.')) extends FakeAIProvider {
            protected function streamChunks(Message $response): Generator
            {
                \Amp\delay(0.15);
                yield new TextChunk('reply', $response->getContent() ?? '');

                return $response;
            }
        };
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $terminal = new VirtualTerminal(rows: 30);
        $queued = '';
        EventLoop::queue(static fn () => $terminal->simulateInput("First\r"));
        EventLoop::delay(0.04, static fn () => $terminal->simulateInput("Second\r"));
        EventLoop::delay(0.08, static function () use ($terminal, &$queued): void {
            $queued = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        });
        EventLoop::delay(0.4, static fn () => $terminal->simulateInput("\x03"));

        Tui::make($agent, $terminal)->addUserMessageProcessor(new EnvelopeProcessor('A'))->run();

        self::assertStringContainsString('↳ Second', $queued);
        self::assertStringNotContainsString('A[Second]', $queued);
        self::assertCount(2, $provider->getRecorded());
        self::assertSame('A[Second]', $provider->getRecorded()[1]->messages[2]->getContent());
    }

    public function testRegistrationIsClosedOnceTheTuiHasRun(): void
    {
        $terminal = new VirtualTerminal();
        $tui = Tui::make(new Agent(), $terminal);
        EventLoop::queue(static fn () => $terminal->simulateInput("\x03"));
        $tui->run();

        $this->expectException(LogicException::class);
        $tui->addUserMessageProcessor(new EnvelopeProcessor('A'));
    }
}

final readonly class EnvelopeProcessor implements UserMessageProcessorInterface
{
    public function __construct(private string $label)
    {
    }

    public function forAgent(string $input): string
    {
        return $this->label . '[' . $input . ']';
    }

    public function forDisplay(string $content): string
    {
        return str_starts_with($content, $this->label . '[') && str_ends_with($content, ']')
            ? substr($content, strlen($this->label) + 1, -1)
            : $content;
    }
}
