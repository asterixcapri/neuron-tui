<?php

declare(strict_types=1);

namespace NeuronCli\Tests\Conversation;

use Generator;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\Tool;
use NeuronCli\Conversation\AgentTurn;
use NeuronCli\Tui\ConversationView;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class AgentTurnTest extends TestCase
{
    private ?ConversationView $view = null;

    public function testTheAnsweredTextIsPaintedIntoTheConversation(): void
    {
        $terminal = new VirtualTerminal(rows: 24);
        $turn = $this->turn(
            new class(new AssistantMessage('Forty-two.')) extends FakeAIProvider {
                protected function streamChunks(Message $response): Generator
                {
                    yield new TextChunk('turn-stream', 'Forty');
                    yield new TextChunk('turn-stream', '-two.');

                    return $response;
                }
            },
            $terminal,
        );

        $display = $this->respond($turn, 'What is the answer?', $terminal);

        self::assertStringContainsString('● Forty-two.', $display);
        self::assertStringNotContainsString('Empty response.', $display);
    }

    public function testAnAnswerWithNothingInItIsCalledEmpty(): void
    {
        $terminal = new VirtualTerminal(rows: 24);
        $turn = $this->turn(
            new FakeAIProvider(new AssistantMessage()),
            $terminal,
        );

        $display = $this->respond($turn, 'Anything?', $terminal);

        self::assertStringContainsString('Empty response.', $display);
    }

    public function testAnAnswerOfWhitespaceAloneIsStillEmpty(): void
    {
        $terminal = new VirtualTerminal(rows: 24);
        $turn = $this->turn(
            new class(new AssistantMessage()) extends FakeAIProvider {
                protected function streamChunks(Message $response): Generator
                {
                    yield new TextChunk('blank-stream', '');
                    yield new TextChunk('blank-stream', " \n\t ");

                    return $response;
                }
            },
            $terminal,
        );

        $display = $this->respond($turn, 'Anything?', $terminal);

        self::assertStringContainsString('Empty response.', $display);
    }

    public function testATurnSpentOnToolsAloneIsNotAnEmptyAnswer(): void
    {
        $terminal = new VirtualTerminal(columns: 100, rows: 24);
        $tool = (new Tool('lookup'))
            ->setCallId('lookup-call')
            ->setInputs(['q' => 'alpha'])
            ->setCallable(static fn (): string => 'alpha result');
        $turn = $this->turn(
            new FakeAIProvider(
                new ToolCallMessage(tools: [$tool]),
                new AssistantMessage(),
            ),
            $terminal,
        );

        $display = $this->respond($turn, 'Run the tool.', $terminal);

        self::assertStringContainsString('● lookup {"q":"alpha"}', $display);
        self::assertStringContainsString('⎿ alpha result', $display);
        self::assertStringNotContainsString('Empty response.', $display);
    }

    private function turn(
        FakeAIProvider $provider,
        VirtualTerminal $terminal,
    ): AgentTurn {
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $view = new ConversationView($terminal, 'Neuron AI', 'Conversation');
        $this->view = $view;

        return new AgentTurn($agent, $view, $view->workingIndicator());
    }

    private function respond(
        AgentTurn $turn,
        string $message,
        VirtualTerminal $terminal,
    ): string {
        EventLoop::queue(
            static fn () => $turn->respond($message),
        );
        EventLoop::run();

        self::assertInstanceOf(ConversationView::class, $this->view);
        $this->view->paintPendingChanges();

        return AnsiUtils::stripAnsiCodes($terminal->getOutput());
    }
}
