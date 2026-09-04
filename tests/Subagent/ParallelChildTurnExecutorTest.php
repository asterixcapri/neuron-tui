<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Subagent;

use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Tools\Tool;
use NeuronTui\Conversation\ConversationPort;
use NeuronTui\Conversation\SubagentReply;
use NeuronTui\Subagent\ChildTurn;
use NeuronTui\Subagent\ParallelChildTurnExecutor;
use NeuronTui\Subagent\Subagents;
use NeuronTui\Tests\Subagent\Fixture\ProcessWorkerAgent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

final class ParallelChildTurnExecutorTest extends TestCase
{
    /** @var list<ParallelChildTurnExecutor> */
    private array $executors = [];

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->executors as $executor) {
            $executor->cancel();
        }

        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }

        putenv('NEURON_TUI_PROCESS_TEST_EVENTS');
        putenv('NEURON_TUI_PROCESS_TEST_RELEASE');
    }

    public function testHistoryRoundTripsAcrossProcessesAndAWarmWorkerIsReused(): void
    {
        $executor = $this->executor(1);
        $first = $executor->execute(
            new ChildTurn(ProcessWorkerAgent::class, 'first', []),
            new \Amp\NullCancellation(),
        )->await();
        $firstPayload = $this->payload($first->reply);

        $second = $executor->execute(
            new ChildTurn(
                ProcessWorkerAgent::class,
                'second',
                $first->history,
            ),
            new \Amp\NullCancellation(),
        )->await();
        $secondPayload = $this->payload($second->reply);

        self::assertSame($firstPayload['pid'], $secondPayload['pid']);
        self::assertSame(['first', 'second'], $secondPayload['users']);
        self::assertSame(
            ['user', 'assistant', 'user', 'assistant'],
            array_column($second->history, 'role'),
        );
    }

    public function testClosureBackedToolHistoryCrossesTheRealProcessSeam(): void
    {
        $tool = (new Tool('local_lookup'))
            ->setCallId('lookup-1')
            ->setInputs(['query' => 'before'])
            ->setCallable(static fn (): string => 'closure must stay local')
            ->setResult('stored result');
        $history = new InMemoryChatHistory();
        $history->addMessage(new ToolCallMessage(tools: [$tool]));
        $history->addMessage(new ToolResultMessage([$tool]));
        $history->addMessage(new AssistantMessage('Tool result understood.'));
        $serialized = $this->plainHistory($history->jsonSerialize());

        $result = $this->executor(1)->execute(
            new ChildTurn(ProcessWorkerAgent::class, 'after tool', $serialized),
            new \Amp\NullCancellation(),
        )->await();

        self::assertSame('tool_call', $result->history[0]['type']);
        self::assertSame('tool_call_result', $result->history[1]['type']);
        self::assertSame('stored result', $this->firstToolResult($result->history));
        self::assertSame(
            ['after tool'],
            $this->payload($result->reply)['users'],
        );
        self::assertStringNotContainsString(
            'closure must stay local',
            serialize(new ChildTurn(
                ProcessWorkerAgent::class,
                'after tool',
                $serialized,
            )),
        );
    }

    public function testRealWorkersRespectTheLimitAndQueuedWorkAdvances(): void
    {
        [$events, $release] = $this->processBarrier();
        $replies = [];
        $port = new ConversationPort(
            static function (SubagentReply $reply) use (&$replies): void {
                $replies[] = $reply;
            },
        );
        $executor = $this->executor(2);
        $subagents = new Subagents(
            ProcessWorkerAgent::class,
            2,
            $executor,
        );
        $subagents->connect($port);
        $first = $subagents->start('wait:first');
        $second = $subagents->start('wait:second');
        $third = $subagents->start('wait:third');

        $this->runUntil(
            fn (): bool => count($this->events($events)) === 2,
            'Two workers did not start.',
        );
        self::assertSame('queued', $subagents->status($third['id'])['state']);
        self::assertEqualsCanonicalizing(
            ['started:first', 'started:second'],
            $this->events($events),
        );

        touch($release);
        $this->runUntil(
            static function () use (&$replies): bool {
                return count($replies) === 3;
            },
            'The queued child Turn did not advance.',
        );

        self::assertSame('idle', $subagents->status($first['id'])['state']);
        self::assertSame('idle', $subagents->status($second['id'])['state']);
        self::assertSame('idle', $subagents->status($third['id'])['state']);
        self::assertContains('started:third', $this->events($events));
    }

    #[DataProvider('failureMessages')]
    public function testRealTransportFailuresBecomeStableDomainReplies(
        string $message,
    ): void {
        $replies = [];
        $port = new ConversationPort(
            static function (SubagentReply $reply) use (&$replies): void {
                $replies[] = $reply;
            },
        );
        $subagents = new Subagents(
            ProcessWorkerAgent::class,
            executor: $this->executor(1),
        );
        $subagents->connect($port);
        $id = $subagents->start($message)['id'];

        $this->runUntil(
            static function () use (&$replies): bool {
                return $replies !== [];
            },
            'The failed child Turn did not produce an outcome.',
        );

        self::assertSame('failed', $subagents->status($id)['state']);
        self::assertSame(
            'The subagent failed while processing its turn.',
            $replies[0]->contents,
        );
        self::assertStringNotContainsString('Amp\\', $replies[0]->contents);
        self::assertStringNotContainsString('ProviderException', $replies[0]->contents);
    }

    /** @return iterable<string, array{string}> */
    public static function failureMessages(): iterable
    {
        yield 'provider exception' => ['provider-failure'];
        yield 'worker crash' => ['worker-crash'];
    }

    public function testCancellationKillsRealWorkAndRejectsItsLateReply(): void
    {
        [$events, $release] = $this->processBarrier();
        $replies = [];
        $port = new ConversationPort(
            static function (SubagentReply $reply) use (&$replies): void {
                $replies[] = $reply;
            },
        );
        $subagents = new Subagents(
            ProcessWorkerAgent::class,
            executor: $this->executor(1),
        );
        $subagents->connect($port);
        $id = $subagents->start('wait:late')['id'];
        $this->runUntil(
            fn (): bool => $this->events($events) === ['started:late'],
            'The cancellable worker did not start.',
        );

        $port->close();
        touch($release);
        EventLoop::run();

        self::assertSame([], $replies);

        try {
            $subagents->status($id);
            self::fail('Cancellation retained the child ID.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('Unknown subagent ID', $exception->getMessage());
        }
    }

    private function executor(int $concurrency): ParallelChildTurnExecutor
    {
        $executor = new ParallelChildTurnExecutor($concurrency);
        $this->executors[] = $executor;

        return $executor;
    }

    /** @return array{pid: int, users: list<string>} */
    private function payload(string $reply): array
    {
        /** @var array{pid: int, users: list<string>} */
        return json_decode($reply, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<int, mixed> $history
     * @return list<array<string, mixed>>
     */
    private function plainHistory(array $history): array
    {
        /** @var list<array<string, mixed>> */
        return json_decode(
            json_encode($history, JSON_THROW_ON_ERROR),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    /** @return array{string, string} */
    private function processBarrier(): array
    {
        $events = tempnam(sys_get_temp_dir(), 'neuron-events-');
        $release = tempnam(sys_get_temp_dir(), 'neuron-release-');

        if ($events === false || $release === false) {
            throw new \RuntimeException('Unable to create process test files.');
        }

        unlink($release);
        $this->temporaryFiles[] = $events;
        $this->temporaryFiles[] = $release;
        putenv("NEURON_TUI_PROCESS_TEST_EVENTS={$events}");
        putenv("NEURON_TUI_PROCESS_TEST_RELEASE={$release}");

        return [$events, $release];
    }

    /** @param list<array<string, mixed>> $history */
    private function firstToolResult(array $history): string
    {
        $tools = $history[1]['tools'] ?? null;

        if (!is_array($tools)) {
            self::fail('The tool result History entry has no tools.');
        }

        $first = $tools[0] ?? null;

        if (!is_array($first) || !is_string($first['result'] ?? null)) {
            self::fail('The first History tool has no string result.');
        }

        return $first['result'];
    }

    /** @return list<string> */
    private function events(string $file): array
    {
        $contents = file_get_contents($file);

        if ($contents === false || trim($contents) === '') {
            return [];
        }

        return explode("\n", trim($contents));
    }

    /** @param callable(): bool $condition */
    private function runUntil(callable $condition, string $failure): void
    {
        $watcher = EventLoop::repeat(0.01, static function () use ($condition): void {
            if ($condition()) {
                EventLoop::getDriver()->stop();
            }
        });
        $timeout = EventLoop::delay(5, static function (): void {
            EventLoop::getDriver()->stop();
        });
        EventLoop::run();
        EventLoop::cancel($watcher);
        EventLoop::cancel($timeout);

        self::assertTrue($condition(), $failure);
    }
}
