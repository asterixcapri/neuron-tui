<?php

declare(strict_types=1);

namespace NeuronTui\Tests\History;

use Generator;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Anthropic\MessageMapper as AnthropicMapper;
use NeuronAI\Providers\Gemini\MessageMapper as GeminiMapper;
use NeuronAI\Providers\OpenAI\MessageMapper as ChatMapper;
use NeuronAI\Providers\OpenAI\Responses\MessageMapper as ResponsesMapper;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;
use NeuronInteraction\Session\Session;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\FileStorage;
use NeuronTui\Conversation\TurnInterruption;
use NeuronTui\Conversation\TurnRunner;
use NeuronTui\History\HistoryProjection;
use NeuronTui\History\ProjectedEntryKind;
use NeuronTui\View\ConversationView;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class InterruptedSessionTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/neuron-interrupted-session-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function responseBoundaries(): iterable
    {
        yield 'partial stream' => [true, false];
        yield 'before first chunk' => [false, false];
        yield 'partial at completion' => [true, true];
        yield 'no text at completion' => [false, true];
    }

    #[DataProvider('responseBoundaries')]
    public function testInterruptedResponsesSurviveReloadAndTheNextProviderInput(bool $hasText, bool $completed): void
    {
        $session = $this->sessionStore()->create(['topic' => 'Preserved metadata']);
        $interruption = new TurnInterruption();
        $provider = $this->interruptedProvider($interruption, $hasText, $completed);

        $this->runTurn($session, $provider, $interruption);
        $reopened = $this->reopen($session);
        $messages = $reopened->getMessages();

        self::assertCount($hasText ? 2 : 1, $messages);
        self::assertInstanceOf(UserMessage::class, $messages[0]);
        self::assertSame('Original question.', $messages[0]->getContent());
        self::assertSame('Preserved metadata', $reopened->getMetadata()['topic']);
        if ($hasText) {
            self::assertInstanceOf(AssistantMessage::class, $messages[1]);
            self::assertSame('Exact partial text.', $messages[1]->getContent());
            self::assertSame('interrupted', $messages[1]->stopReason());
        } else {
            self::assertSame('interrupted', $messages[0]->getMetadata('stop_reason'));
        }

        $entries = (new HistoryProjection($messages))->entries();
        self::assertSame($hasText
            ? [ProjectedEntryKind::Person, ProjectedEntryKind::Agent, ProjectedEntryKind::Notice]
            : [ProjectedEntryKind::Person, ProjectedEntryKind::Notice], array_column($entries, 'kind'));
        self::assertSame('Turn interrupted.', $entries[count($entries) - 1]->text);
        $input = $this->continueSession($reopened);
        self::assertSame([...$messages, $input[count($input) - 1]], $input);
        $this->assertProviderMapping($input, $hasText ? 'Exact partial text.' : null);
    }

    /** @return iterable<string, array{bool}> */
    public static function toolOutcomes(): iterable
    {
        yield 'completed then skipped' => [false];
        yield 'completed then failed then skipped' => [true];
    }

    #[DataProvider('toolOutcomes')]
    public function testEveryToolOutcomeAndIdentitySurvivesReload(bool $fails): void
    {
        $session = $this->sessionStore()->create();
        $interruption = new TurnInterruption();
        $runs = [];
        $first = (new Tool('read_record'))->setCallId('call-read')->setInputs(['record' => 17])
            ->setCallable(static function () use (&$runs): string {
                $runs[] = 'read';

                return "Exact real result.\nSecond line.";
            });
        $second = (new Tool('update_record'))->setCallId('call-update')->setInputs(['record' => 23])
            ->setCallable(static function () use (&$runs, $interruption, $fails): string {
                $runs[] = 'update';
                $interruption->request();

                if ($fails) {
                    throw new RuntimeException('Actual update failure: record 23.');
                }

                return 'Actual update result: record 23.';
            });
        $third = (new Tool('delete_record'))->setCallId('call-delete')->setInputs(['record' => 42])
            ->setCallable(static function () use (&$runs): string {
                $runs[] = 'delete';

                return 'Must never execute.';
            });
        $call = new ToolCallMessage(tools: [$first, $second, $third]);
        $call->addMetadata('thought_signature', 'original-provider-signature');
        $provider = new FakeAIProvider($call);

        $this->runTurn($session, $provider, $interruption);
        $provider->assertCallCount(1);
        self::assertSame(['read', 'update'], $runs);
        $before = $session->getMessages();
        self::assertInstanceOf(ToolResultMessage::class, $before[2]);
        $expectedBodies = array_map(static fn (ToolInterface $tool): string => $tool->getResult(), $before[2]->getTools());

        $reopened = $this->reopen($session);
        $messages = $reopened->getMessages();
        self::assertCount(3, $messages);
        self::assertInstanceOf(ToolCallMessage::class, $messages[1]);
        self::assertSame('interrupted', $messages[1]->stopReason());
        self::assertSame('original-provider-signature', $messages[1]->getMetadata('thought_signature'));
        self::assertInstanceOf(ToolResultMessage::class, $messages[2]);
        $results = $messages[2]->getTools();
        self::assertCount(3, $results);
        foreach ($results as $index => $result) {
            $original = $call->getTools()[$index];
            self::assertSame($original->getCallId(), $result->getCallId());
            self::assertSame($original->getName(), $result->getName());
            self::assertSame($original->getInputs(), $result->getInputs());
            self::assertSame($expectedBodies[$index], $result->getResult());
        }
        self::assertSame("Exact real result.\nSecond line.", $results[0]->getResult());
        if ($fails) {
            self::assertSame([
                'neuron_tui' => 'tool_outcome',
                'status' => 'failed',
                'error_type' => RuntimeException::class,
                'message' => 'Actual update failure: record 23.',
            ], json_decode($results[1]->getResult(), true, flags: JSON_THROW_ON_ERROR));
        } else {
            self::assertSame('Actual update result: record 23.', $results[1]->getResult());
        }
        self::assertSame([
            'neuron_tui' => 'tool_outcome',
            'status' => 'not_executed',
            'reason' => 'turn_interrupted',
            'message' => 'Execution never began because the person interrupted the Turn.',
        ], json_decode($results[2]->getResult(), true, flags: JSON_THROW_ON_ERROR));

        $entries = (new HistoryProjection($messages))->entries();
        self::assertSame([
            ProjectedEntryKind::Person, ProjectedEntryKind::Tool, ProjectedEntryKind::Tool,
            ProjectedEntryKind::Tool, ProjectedEntryKind::Notice,
        ], array_column($entries, 'kind'));
        self::assertStringContainsString('Done in', $entries[1]->text);
        self::assertStringContainsString($fails ? 'Failed in' : 'Done in', $entries[2]->text);
        self::assertStringContainsString('Not executed', $entries[3]->text);
        self::assertStringNotContainsString('Done', $entries[3]->text);
        $input = $this->continueSession($reopened);
        self::assertCount(4, $input);
        $this->assertProviderMapping($input, results: array_values($results));
    }

    public function testReloadKeepsToolResultsAndOnlyThePartialTextFromTheFollowingInference(): void
    {
        $session = $this->sessionStore()->create();
        $interruption = new TurnInterruption();
        $tool = (new Tool('lookup'))->setCallId('lookup-id')->setInputs(['record' => 17])
            ->setCallable(static fn (): string => 'Actual lookup result.');
        $call = new ToolCallMessage('Planning prose.', [$tool]);
        $call->addMetadata('thought_signature', 'original-provider-signature');
        $provider = $this->interruptedProvider($interruption, true, false, $call);

        $this->runTurn($session, $provider, $interruption);
        $provider->assertCallCount(2);
        $reopened = $this->reopen($session);
        $messages = $reopened->getMessages();
        self::assertCount(4, $messages);
        self::assertInstanceOf(ToolCallMessage::class, $messages[1]);
        self::assertSame('Planning prose.', $messages[1]->getContent());
        self::assertInstanceOf(ToolResultMessage::class, $messages[2]);
        self::assertSame('Actual lookup result.', $messages[2]->getTools()[0]->getResult());
        self::assertSame('Exact partial text.', $messages[3]->getContent());
        self::assertSame('interrupted', $messages[3]->getMetadata('stop_reason'));
        $entries = (new HistoryProjection($messages))->entries();
        self::assertSame([
            ProjectedEntryKind::Person, ProjectedEntryKind::Agent, ProjectedEntryKind::Tool,
            ProjectedEntryKind::Agent, ProjectedEntryKind::Notice,
        ], array_column($entries, 'kind'));
        $this->assertProviderMapping(
            $this->continueSession($reopened),
            'Exact partial text.',
            array_values($messages[2]->getTools()),
        );
    }

    public function testCompletionReconciliationDoesNotClearHistoryOrReplayMessageHooks(): void
    {
        $history = new class extends InMemoryChatHistory {
            public int $clears = 0;
            /** @var list<Message> */
            public array $added = [];
            /** @var list<list<Message>> */
            public array $snapshots = [];

            protected function clear(): void
            {
                ++$this->clears;
            }

            protected function onNewMessage(Message $message): void
            {
                $this->added[] = $message;
            }

            protected function setMessages(array $messages): void
            {
                $this->snapshots[] = array_values($messages);
            }
        };
        $history->addMessage(new UserMessage('Previous question.'));
        $history->addMessage(new AssistantMessage('Previous answer.'));
        $interruption = new TurnInterruption();

        $this->runTurn($history, $this->interruptedProvider($interruption, true, true), $interruption);

        self::assertSame(0, $history->clears);
        self::assertCount(4, $history->added);
        self::assertCount(5, $history->snapshots);
        self::assertSame([1, 2, 3, 4, 4], array_map(count(...), $history->snapshots));
        self::assertSame('Exact partial text.', $history->getLastMessage()->getContent());
        self::assertSame('interrupted', $history->getLastMessage()->getMetadata('stop_reason'));
    }

    public function testIncrementalHistoryPersistenceStillReceivesTheReconciledMessages(): void
    {
        $history = new class extends InMemoryChatHistory {
            /** @var list<string> */
            public array $stored = [];

            protected function clear(): void
            {
                $this->stored = [];
            }

            protected function onNewMessage(Message $message): void
            {
                $this->stored[] = json_encode($message, JSON_THROW_ON_ERROR);
            }
        };
        $history->addMessage(new UserMessage('Previous question.'));
        $history->addMessage(new AssistantMessage('Previous answer.'));
        $interruption = new TurnInterruption();

        $this->runTurn($history, $this->interruptedProvider($interruption, true, true), $interruption);

        self::assertCount(4, $history->stored);
        self::assertSame(array_map(
            static fn (Message $message): string => json_encode($message, JSON_THROW_ON_ERROR),
            $history->getMessages(),
        ), $history->stored);
        self::assertStringContainsString('Exact partial text.', $history->stored[3]);
        self::assertStringNotContainsString('Unseen provider wording.', $history->stored[3]);
    }

    private function interruptedProvider(TurnInterruption $interruption, bool $hasText, bool $completed, ?ToolCallMessage $call = null): FakeAIProvider
    {
        return new class($interruption, $hasText, $completed, $call) extends FakeAIProvider {
            public function __construct(
                private readonly TurnInterruption $interruption,
                private readonly bool $hasText,
                private readonly bool $completed,
                ?ToolCallMessage $call,
            ) {
                $responses = $call === null ? [] : [$call];
                $responses[] = new AssistantMessage('Unseen provider wording.');
                parent::__construct(...$responses);
            }

            protected function streamChunks(Message $response): Generator
            {
                if ($response instanceof ToolCallMessage) {
                    yield from parent::streamChunks($response);

                    return $response;
                }

                if ($this->hasText) {
                    yield new TextChunk('response', 'Exact partial text.');
                }

                $this->interruption->request();

                if (!$this->completed) {
                    yield new TextChunk('response', 'Unseen provider wording.');
                }

                return $response;
            }
        };
    }

    private function runTurn(ChatHistoryInterface $history, FakeAIProvider $provider, ?TurnInterruption $interruption = null, string $text = 'Original question.'): void
    {
        $agent = new Agent();
        $agent->setChatHistory($history);
        $agent->setAiProvider($provider);
        $runner = new TurnRunner(new ConversationView(new VirtualTerminal(), 'Neuron AI', 'Conversation'));
        EventLoop::queue(static fn () => $runner->run($agent, $text, $interruption));
        EventLoop::run();
        self::assertSame($history, $agent->getChatHistory());
    }

    private function sessionStore(): SessionStore
    {
        return new SessionStore(new FileStorage($this->directory), 'alice');
    }

    private function reopen(Session $session): Session
    {
        $reopened = $this->sessionStore()->read($session->getKey());
        self::assertNotNull($reopened);
        self::assertNotSame($session, $reopened);

        return $reopened;
    }

    /** @return list<Message> */
    private function continueSession(Session $session): array
    {
        $provider = new FakeAIProvider(new AssistantMessage('Next answer.'));
        $this->runTurn($session, $provider, text: 'Next question.');
        $provider->assertCallCount(1);
        $input = $provider->getRecorded()[0]->messages;
        self::assertSame('Next question.', $input[count($input) - 1]->getContent());
        self::assertSame('Next answer.', $this->reopen($session)->getLastMessage()->getContent());

        return array_values($input);
    }

    /** @param list<Message> $input
     *  @param list<ToolInterface> $results
     */
    private function assertProviderMapping(array $input, ?string $partial = null, array $results = []): void
    {
        foreach ([new ChatMapper(), new ResponsesMapper(), new AnthropicMapper(), new GeminiMapper()] as $mapper) {
            $payload = json_encode($mapper->map($input), JSON_THROW_ON_ERROR);
            self::assertStringContainsString('Next question.', $payload);
            self::assertStringNotContainsString('Turn interrupted.', $payload);
            self::assertStringNotContainsString('stop_reason', $payload);
            self::assertStringNotContainsString('Unseen provider wording.', $payload);
            if ($partial !== null) {
                self::assertStringContainsString($partial, $payload);
            }
            foreach ($results as $result) {
                self::assertStringContainsString(json_encode($result->getResult(), JSON_THROW_ON_ERROR), $payload);
                self::assertStringContainsString(json_encode($result->getInputs(), JSON_THROW_ON_ERROR), stripslashes($payload));
                $id = json_encode($result->getCallId(), JSON_THROW_ON_ERROR);
                if ($mapper instanceof ChatMapper) {
                    self::assertSame(1, substr_count($payload, '"id":' . $id));
                    self::assertSame(1, substr_count($payload, '"tool_call_id":' . $id));
                } elseif ($mapper instanceof ResponsesMapper) {
                    self::assertSame(2, substr_count($payload, '"call_id":' . $id));
                } elseif ($mapper instanceof AnthropicMapper) {
                    self::assertSame(1, substr_count($payload, '"id":' . $id));
                    self::assertSame(1, substr_count($payload, '"tool_use_id":' . $id));
                } else {
                    self::assertStringContainsString('"functionCall":{"name":"' . $result->getName() . '"', $payload);
                    self::assertStringContainsString('"functionResponse":{"name":"' . $result->getName() . '"', $payload);
                    self::assertStringContainsString('original-provider-signature', $payload);
                }
            }
        }
    }
}
