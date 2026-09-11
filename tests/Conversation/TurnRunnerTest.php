<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Conversation;

use Generator;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\Tool;
use NeuronTui\Conversation\TurnRunner;
use NeuronTui\View\ConversationView;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class TurnRunnerTest extends TestCase
{
    public function testTheAnsweredTextIsPaintedIntoTheConversation(): void
    {
        $terminal = new VirtualTerminal(rows: 24);
        $provider = new class(
            new AssistantMessage('Forty-two.'),
        ) extends FakeAIProvider {
            protected function streamChunks(Message $response): Generator
            {
                yield new TextChunk('turn-stream', 'Forty');
                yield new TextChunk('turn-stream', '-two.');

                return $response;
            }
        };

        $display = $this->runTurn(
            $provider,
            'What is the answer?',
            $terminal,
        );

        self::assertStringContainsString('● Forty-two.', $display);
        self::assertStringNotContainsString('Empty response.', $display);
    }

    public function testAnAnswerWithNothingInItIsCalledEmpty(): void
    {
        $terminal = new VirtualTerminal(rows: 24);

        $display = $this->runTurn(
            new FakeAIProvider(new AssistantMessage()),
            'Anything?',
            $terminal,
        );

        self::assertStringContainsString('Empty response.', $display);
    }

    public function testProgressAfterToolsAppearsAsANewMessageInStreamOrder(): void
    {
        $terminal = new VirtualTerminal(columns: 120, rows: 40);
        $lookup = (new Tool('lookup'))
            ->setCallId('lookup-call')
            ->setInputs([])
            ->setCallable(static fn (): string => 'Found the record.');
        $check = (new Tool('check'))
            ->setCallId('check-call')
            ->setInputs([])
            ->setCallable(static fn (): string => 'Record verified.');
        $provider = new FakeAIProvider(
            new ToolCallMessage('Finding the record.', [$lookup]),
            new ToolCallMessage('Found it; checking the record.', [$check]),
            new AssistantMessage('The record is verified.'),
        );

        $display = $this->runTurn($provider, 'Find and verify the record.', $terminal);

        self::assertMatchesRegularExpression(
            '/● Finding the record\..*● lookup.*● Found it; checking the record\..*● check.*● The record is verified\./s',
            $display,
        );
        $provider->assertCallCount(3);
    }

    public function testAnAnswerOfWhitespaceAloneIsStillEmpty(): void
    {
        $terminal = new VirtualTerminal(rows: 24);
        $provider = new class(new AssistantMessage()) extends FakeAIProvider {
            protected function streamChunks(Message $response): Generator
            {
                yield new TextChunk('blank-stream', '');
                yield new TextChunk('blank-stream', " \n\t ");

                return $response;
            }
        };

        $display = $this->runTurn($provider, 'Anything?', $terminal);

        self::assertStringContainsString('Empty response.', $display);
    }

    public function testATurnSpentOnToolsAloneIsNotAnEmptyAnswer(): void
    {
        $terminal = new VirtualTerminal(columns: 100, rows: 24);
        $tool = (new Tool('lookup'))
            ->setCallId('lookup-call')
            ->setInputs(['q' => 'alpha'])
            ->setCallable(static fn (): string => 'alpha result');
        $provider = new FakeAIProvider(
            new ToolCallMessage(tools: [$tool]),
            new AssistantMessage(),
        );

        $display = $this->runTurn($provider, 'Run the tool.', $terminal);

        self::assertStringContainsString('● lookup {"q":"alpha"}', $display);
        self::assertStringContainsString('⎿ alpha result', $display);
        self::assertStringNotContainsString('Empty response.', $display);
    }

    public function testAnEmptyTextChunkBeforeAToolDoesNotCreateAnEmptyMessage(): void
    {
        $terminal = new VirtualTerminal(columns: 100, rows: 24);
        $tool = (new Tool('lookup'))
            ->setCallId('lookup-call')
            ->setInputs(['q' => 'alpha'])
            ->setCallable(static fn (): string => 'alpha result');
        $provider = new class(
            new ToolCallMessage(tools: [$tool]),
            new AssistantMessage('Found it.'),
        ) extends FakeAIProvider {
            protected function streamChunks(Message $response): Generator
            {
                if ($response instanceof ToolCallMessage) {
                    yield new TextChunk('empty-before-tool', '');

                    return $response;
                }

                yield from parent::streamChunks($response);

                return $response;
            }
        };

        $display = $this->runTurn($provider, 'Run the tool.', $terminal);

        self::assertDoesNotMatchRegularExpression('/\R ●\h+\R/', $display);
        self::assertStringContainsString('● lookup {"q":"alpha"}', $display);
        self::assertStringContainsString('● Found it.', $display);
    }

    public function testEachTurnIsAnsweredByTheAgentHandedToItThen(): void
    {
        $terminal = new VirtualTerminal(rows: 24);
        $first = new FakeAIProvider(new AssistantMessage('The first one.'));
        $second = new FakeAIProvider(new AssistantMessage('The second one.'));
        $view = new ConversationView($terminal, 'Neuron AI', 'Conversation');
        $turn = new TurnRunner($view);
        $earlier = $this->agentOf($first);
        $later = $this->agentOf($second);

        EventLoop::queue(
            static function () use ($turn, $earlier, $later): void {
                $turn->run($earlier, 'Who answers?');
                $turn->run($later, 'And now?');
            },
        );
        EventLoop::run();

        $view->paintPendingChanges();
        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString('The second one.', $display);
        $first->assertCallCount(1);
        $second->assertCallCount(1);
    }

    private function agentOf(FakeAIProvider $provider): Agent
    {
        $agent = new Agent();
        $agent->setAiProvider($provider);

        return $agent;
    }

    /**
     * Takes one turn against the given provider and reads back what the
     * terminal was told to show.
     */
    private function runTurn(
        FakeAIProvider $provider,
        string $message,
        VirtualTerminal $terminal,
    ): string {
        $agent = $this->agentOf($provider);
        $view = new ConversationView($terminal, 'Neuron AI', 'Conversation');
        $turn = new TurnRunner($view);

        EventLoop::queue(
            static fn () => $turn->run($agent, $message),
        );
        EventLoop::run();

        $view->paintPendingChanges();

        return AnsiUtils::stripAnsiCodes($terminal->getOutput());
    }
}
