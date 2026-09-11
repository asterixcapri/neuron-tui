<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Conversation;

use Generator;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\Tool;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronTui\Conversation\TurnInterruption;
use NeuronTui\Conversation\TurnRunner;
use NeuronTui\History\HistoryProjection;
use NeuronTui\History\ProjectedEntryKind;
use NeuronTui\View\ConversationView;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use RuntimeException;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class ResponseInterruptionTest extends TestCase
{
    public function testARequestDuringToolsLetsEveryCallAndFollowingInferenceRun(): void
    {
        $interruption = new TurnInterruption();
        $executed = [];
        $first = (new Tool('first'))->setCallId('one')->setInputs([])->setCallable(
            static function () use ($interruption, &$executed): string {
                $executed[] = 'first';
                $interruption->request();

                return 'First result';
            },
        );
        $second = (new Tool('second'))->setCallId('two')->setInputs([])->setCallable(
            static function () use (&$executed): never {
                $executed[] = 'second';

                throw new RuntimeException('Expected failure');
            },
        );
        $third = (new Tool('third'))->setCallId('three')->setInputs([])->setCallable(
            static function () use (&$executed): string {
                $executed[] = 'third';

                return 'Third result';
            },
        );
        $provider = new FakeAIProvider(
            new ToolCallMessage(tools: [$first, $second]),
            new ToolCallMessage(tools: [$third]),
            new AssistantMessage('Partial answer'),
            new AssistantMessage('Next answer'),
        );
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $agent->toolErrorHandler(static fn (\Throwable $error): string => 'Host handled: ' . $error->getMessage());
        $history = $agent->getChatHistory();

        $this->runTurn($agent, $interruption);

        self::assertSame($history, $agent->getChatHistory());
        self::assertSame(['first', 'second', 'third'], $executed);
        $messages = $history->getMessages();
        self::assertCount(6, $messages);
        self::assertInstanceOf(ToolResultMessage::class, $messages[2]);
        self::assertSame('First result', $messages[2]->getTools()[0]->getResult());
        self::assertSame('Host handled: Expected failure', $messages[2]->getTools()[1]->getResult());
        self::assertInstanceOf(ToolResultMessage::class, $messages[4]);
        self::assertSame('Third result', $messages[4]->getTools()[0]->getResult());
        self::assertSame('interrupted', $messages[5]->getMetadata('stop_reason'));
        $provider->assertCallCount(3);

        $this->runTurn($agent, new TurnInterruption());

        self::assertSame($messages, array_slice($provider->getRecorded()[3]->messages, 0, 6));
        self::assertCount(8, $history->getMessages());
    }

    public function testCompletionAfterTheLastChunkWinsWithoutRelabelingHistory(): void
    {
        $interruption = new TurnInterruption();
        $response = new AssistantMessage('Complete answer');
        $provider = new class($interruption, $response) extends FakeAIProvider {
            public function __construct(private readonly TurnInterruption $interruption, Message $response)
            {
                parent::__construct($response);
            }

            protected function streamChunks(Message $response): Generator
            {
                yield new TextChunk('complete', 'Complete answer');
                $this->interruption->request();

                return $response;
            }
        };
        $agent = new Agent();
        $agent->setAiProvider($provider);

        $this->runTurn($agent, $interruption);

        self::assertSame($response, $agent->getChatHistory()->getLastMessage());
        self::assertNull($response->getMetadata('stop_reason'));
        self::assertFalse($interruption->request());
    }

    public function testARequestWithoutTextDoesNotInventAnInterruptedAnswer(): void
    {
        $interruption = new TurnInterruption();
        $interruption->request();
        $response = new AssistantMessage();
        $agent = new Agent();
        $agent->setAiProvider(new FakeAIProvider($response));

        $this->runTurn($agent, $interruption);

        self::assertSame($response, $agent->getChatHistory()->getLastMessage());
        self::assertNull($response->getMetadata('stop_reason'));
    }

    public function testPartialTextAndNoticeSurviveSessionReloadAndCanBeContinued(): void
    {
        $store = new SessionStore(new InMemoryStorage(), 'test-user');
        $session = $store->create();
        $agent = new Agent();
        $agent->setChatHistory($session);
        $agent->setAiProvider(new FakeAIProvider(new AssistantMessage('Partial answer')));
        $interruption = new TurnInterruption();
        $interruption->request();

        $this->runTurn($agent, $interruption);

        self::assertSame($session, $agent->getChatHistory());
        $reloaded = $store->read($store->summaries()[0]->key);
        self::assertNotNull($reloaded);
        self::assertSame('interrupted', $reloaded->getLastMessage()->getMetadata('stop_reason'));
        $entries = (new HistoryProjection($reloaded->getMessages()))->entries();
        self::assertSame([ProjectedEntryKind::Person, ProjectedEntryKind::Agent, ProjectedEntryKind::Notice], array_column($entries, 'kind'));
        $provider = new FakeAIProvider(new AssistantMessage('Continued'));
        $agent = new Agent();
        $agent->setChatHistory($reloaded);
        $agent->setAiProvider($provider);

        $this->runTurn($agent, new TurnInterruption());

        self::assertCount(4, $reloaded->getMessages());
        self::assertCount(3, $provider->getRecorded()[0]->messages);
        self::assertSame('interrupted', $provider->getRecorded()[0]->messages[1]->getMetadata('stop_reason'));
    }

    private function runTurn(Agent $agent, TurnInterruption $interruption): void
    {
        $runner = new TurnRunner(new ConversationView(new VirtualTerminal(), 'Test', 'Conversation'));
        EventLoop::queue(static fn () => $runner->run($agent, 'Question', $interruption));
        EventLoop::run();
    }
}
