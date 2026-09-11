<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Tui;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Observability\ObserverInterface;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolInterface;
use NeuronTui\History\HistoryProjection;
use NeuronTui\History\ProjectedEntry;
use NeuronTui\History\ProjectedEntryKind;
use NeuronTui\Tests\Fixtures\ParallelBatchTool;
use NeuronTui\Tests\Fixtures\RepeatedCallTool;
use NeuronTui\Tui;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Spatie\Fork\Fork;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Throwable;

final class ParallelToolInterruptionTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        if (!extension_loaded('pcntl')) {
            self::markTestSkipped('Actual parallel tools require pcntl.');
        }

        self::assertTrue(class_exists(Fork::class), 'Install require-dev dependencies to test real parallel execution.');
        $this->directory = sys_get_temp_dir() . '/neuron-tui-parallel-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory)) {
            (new Filesystem())->remove($this->directory);
        }
    }

    /** @return iterable<string, array{?string, bool}> */
    public static function ambiguousCallIds(): iterable
    {
        yield 'repeated ids with success' => ['lookup', false];
        yield 'repeated ids with failure' => ['lookup', true];
        yield 'absent ids with success' => [null, false];
        yield 'absent ids with failure' => [null, true];
    }

    #[DataProvider('ambiguousCallIds')]
    public function testRepeatedCallsRetainDistinctChildOutcomesAndStopBeforeFurtherInference(?string $callId, bool $fails): void
    {
        $terminal = new VirtualTerminal(columns: 140, rows: 45);
        $tools = [
            (new RepeatedCallTool(1, $this->directory, $fails))->setCallId($callId),
            (new RepeatedCallTool(2, $this->directory))->setCallId($callId),
            (new RepeatedCallTool(3, $this->directory))->setCallId($callId),
        ];
        $call = new ToolCallMessage(tools: $tools);
        $provider = new FakeAIProvider($call, new AssistantMessage('Second answer.'), new AssistantMessage('Third answer.'));
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $agent->parallelToolCalls(true);
        $agent->observe(new class($terminal) implements ObserverInterface {
            private bool $requested = false;

            public function __construct(private readonly VirtualTerminal $terminal)
            {
            }

            public function onEvent(string $event, object $source, mixed $data = null, ?string $branchId = null): void
            {
                if ($event === 'tool-called' && !$this->requested) {
                    $this->requested = true;
                    $this->terminal->simulateInput("Second question\rThird question\r\x1b\x1b\x1b");
                }
            }
        });
        EventLoop::queue(static fn () => $terminal->simulateInput("First question\r"));
        EventLoop::delay(0.3, static fn () => $terminal->simulateInput("\x03"));

        (new Tui($agent, terminal: $terminal))->run();

        $messages = $agent->getChatHistory()->getMessages();
        self::assertCount(7, $messages);
        self::assertSame($call, $messages[1]);
        self::assertSame('interrupted', $call->getMetadata('stop_reason'));
        self::assertInstanceOf(ToolResultMessage::class, $messages[2]);
        $results = $messages[2]->getTools();
        self::assertCount(3, $results);
        $pids = [];

        foreach ($results as $index => $result) {
            self::assertSame($callId, $result->getCallId());
            self::assertSame('lookup', $result->getName());
            self::assertSame(['value' => $index + 1], $result->getInputs());
            $pid = file_get_contents($this->directory . '/' . ($index + 1) . '.settled');
            self::assertNotFalse($pid);
            self::assertTrue(ctype_digit($pid));
            self::assertNotSame((string) getmypid(), $pid);
            $pids[] = $pid;

            if ($index === 0 && $fails) {
                self::assertSame([
                    'neuron_tui' => 'tool_outcome',
                    'status' => 'failed',
                    'error_type' => \RuntimeException::class,
                    'message' => 'Lookup 1 failed.',
                ], json_decode($result->getResult(), true, flags: JSON_THROW_ON_ERROR));
            } else {
                self::assertSame('Lookup ' . ($index + 1) . ' completed.', $result->getResult());
            }
        }

        self::assertCount(3, array_unique($pids));
        self::assertSame(['Second question', 'Second answer.', 'Third question', 'Third answer.'], array_map(
            static fn (Message $message): ?string => $message->getContent(),
            array_slice($messages, 3),
        ));
        $provider->assertCallCount(3);
        self::assertSame(array_slice($messages, 0, 4), $provider->getRecorded()[1]->messages);
        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString('Turn interrupted.', $display);
        self::assertStringContainsString($fails ? 'Lookup 1 failed.' : 'Lookup 1 completed.', $display);
        self::assertStringContainsString('Lookup 2 completed.', $display);
        self::assertStringContainsString('Lookup 3 completed.', $display);
        self::assertStringNotContainsString('Not executed', $display);
        $projectedTools = array_values(array_filter(
            (new HistoryProjection($messages))->entries(),
            static fn (ProjectedEntry $entry): bool => $entry->kind === ProjectedEntryKind::Tool,
        ));
        self::assertCount(3, $projectedTools);

        foreach ($projectedTools as $index => $entry) {
            self::assertStringContainsString('lookup {"value":' . ($index + 1) . '}', $entry->text);
            self::assertStringContainsString('Lookup ' . ($index + 1) . ($index === 0 && $fails ? ' failed.' : ' completed.'), $entry->text);
            self::assertStringNotContainsString('Running', $entry->text);
        }
    }

    /** @return iterable<string, array{bool, bool, bool}> */
    public static function interruptions(): iterable
    {
        yield 'successful batch with FIFO' => [false, false, true];
        yield 'first failure retains later successful outcomes with FIFO' => [true, false, true];
        yield 'host error handler and child hooks with FIFO' => [true, true, true];
        yield 'no waiting input returns ready' => [false, false, false];
    }

    #[DataProvider('interruptions')]
    public function testEveryStartedChildSettlesBeforeTheQueueAdvances(bool $fails, bool $hostHandler, bool $queued): void
    {
        $terminal = new VirtualTerminal(columns: 160, rows: 45);
        $directory = $this->directory;
        $tools = [
            new ParallelBatchTool('first', $directory, $fails),
            new ParallelBatchTool('second', $directory),
            new ParallelBatchTool('third', $directory),
        ];
        $call = new ToolCallMessage('Three parallel operations.', $tools);
        $provider = new FakeAIProvider($call, new AssistantMessage('Second answer.'), new AssistantMessage('Third answer.'));
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $agent->parallelToolCalls(
            true,
            static fn () => file_put_contents($directory . '/' . getmypid() . '.before', 'once', FILE_APPEND),
            static fn () => file_put_contents($directory . '/' . getmypid() . '.after', 'once', FILE_APPEND),
        );
        $handled = [];

        if ($hostHandler) {
            $agent->toolErrorHandler(static function (Throwable $failure, ToolInterface $tool) use (&$handled): string {
                $handled[] = $tool->getCallId();

                return 'Host handled: ' . $failure->getMessage();
            });
        }

        $observer = new class($terminal) implements ObserverInterface {
            public string $pending = '';

            public function __construct(private readonly VirtualTerminal $terminal)
            {
            }

            public function onEvent(string $event, object $source, mixed $data = null, ?string $branchId = null): void
            {
                if ($event === 'tool-called') {
                    $this->pending .= AnsiUtils::stripAnsiCodes($this->terminal->getOutput());
                }
            }
        };
        $agent->observe($observer);

        // Fork blocks the parent. This input becomes dispatchable at the first
        // result checkpoint, after the children have really run concurrently.
        $inputWatcher = EventLoop::repeat(0.001, static function (string $id) use ($directory, $terminal, $queued): void {
            if (!is_file($directory . '/first.started')) {
                return;
            }

            EventLoop::cancel($id);
            $terminal->simulateInput(($queued ? "Second question\rThird question\r" : '') . "\x1b\x1b\x1b");
        });
        EventLoop::queue(static fn () => $terminal->simulateInput("First question\r"));
        EventLoop::delay(0.3, static fn () => $terminal->simulateInput("\x03"));

        try {
            (new Tui($agent, terminal: $terminal))->run();
        } finally {
            EventLoop::cancel($inputWatcher);
        }

        $pids = [];

        foreach ($tools as $tool) {
            $pid = file_get_contents($directory . '/' . $tool->getName() . '.started');
            self::assertNotFalse($pid);
            self::assertNotSame((string) getmypid(), $pid);
            $pids[] = $pid;
            self::assertSame('once', file_get_contents($directory . '/' . $tool->getName() . '.settled'));
            self::assertSame('once', file_get_contents($directory . '/' . $pid . '.before'));
            self::assertSame('once', file_get_contents($directory . '/' . $pid . '.after'));
        }

        self::assertCount(3, array_unique($pids));
        self::assertSame($hostHandler ? ['first-id'] : [], $handled);
        $messages = $agent->getChatHistory()->getMessages();
        self::assertCount($queued ? 7 : 3, $messages);
        self::assertSame($call, $messages[1]);
        self::assertInstanceOf(ToolResultMessage::class, $messages[2]);
        $results = $messages[2]->getTools();
        self::assertCount(3, $results);

        foreach ($tools as $index => $original) {
            self::assertSame($original->getCallId(), $results[$index]->getCallId());
            self::assertSame($original->getName(), $results[$index]->getName());
            self::assertSame($original->getInputs(), $results[$index]->getInputs());
        }

        if ($fails && !$hostHandler) {
            self::assertSame([
                'neuron_tui' => 'tool_outcome',
                'status' => 'failed',
                'error_type' => \RuntimeException::class,
                'message' => 'first really failed.',
            ], json_decode($results[0]->getResult(), true, flags: JSON_THROW_ON_ERROR));
        } else {
            self::assertSame($hostHandler ? 'Host handled: first really failed.' : 'first really completed.', $results[0]->getResult());
        }

        self::assertSame('second really completed.', $results[1]->getResult());
        self::assertSame('third really completed.', $results[2]->getResult());
        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString('Interruption requested · waiting for the current tool', $observer->pending);
        self::assertStringContainsString('Turn interrupted.', $display);
        self::assertStringContainsString('ready · Enter sends', $display);
        self::assertStringNotContainsString('Not executed', $display);
        self::assertStringContainsString('second really completed.', $display);
        self::assertStringContainsString('third really completed.', $display);
        self::assertCount(1, array_filter($messages, static fn (Message $message): bool => $message instanceof ToolResultMessage));
        $provider->assertCallCount($queued ? 3 : 1);

        if ($queued) {
            self::assertSame(['Second question', 'Second answer.', 'Third question', 'Third answer.'], array_map(
                static fn (Message $message): ?string => $message->getContent(),
                array_slice($messages, 3),
            ));
            self::assertSame(array_slice($messages, 0, 4), $provider->getRecorded()[1]->messages);
            self::assertSame(array_slice($messages, 0, 6), $provider->getRecorded()[2]->messages);
        }
    }
}
