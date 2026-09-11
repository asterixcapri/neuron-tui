<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Conversation;

use Generator;
use LogicException;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Nodes\ParallelToolNode;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Observability\ObserverInterface;
use NeuronAI\Testing\FakeAIProvider;
use NeuronTui\Conversation\TurnInterruption;
use NeuronTui\Conversation\TurnRunner;
use NeuronTui\Tests\Fixtures\ParallelBatchTool;
use NeuronTui\View\ConversationView;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use RuntimeException;
use Spatie\Fork\Fork;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Throwable;

final class ParallelToolFailureTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        if (!extension_loaded('pcntl')) {
            self::markTestSkipped('Actual parallel tools require pcntl.');
        }

        self::assertTrue(class_exists(Fork::class));
        $this->directory = sys_get_temp_dir() . '/neuron-tui-parallel-failure-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory)) {
            (new Filesystem())->remove($this->directory);
        }
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function failures(): iterable
    {
        yield 'unhandled normal failure' => [false, false];
        yield 'throwing handler normal failure' => [false, true];
        yield 'unhandled interrupted failure' => [true, false];
        yield 'throwing handler interrupted failure' => [true, true];
    }

    #[DataProvider('failures')]
    public function testFailuresKeepTheirMeaningAndTheOriginalHandlerIsRestoredForAgentReuse(bool $interrupt, bool $handlerThrows): void
    {
        $first = $this->batch('first');
        $second = $this->batch('second');
        $provider = new FakeAIProvider($first, $second);
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $agent->parallelToolCalls(true);
        $handled = 0;
        $handler = null;

        if ($handlerThrows) {
            $handler = static function (Throwable $failure) use (&$handled): never {
                ++$handled;

                throw new LogicException('Host handler failed: ' . $failure->getMessage());
            };
            $agent->toolErrorHandler($handler);
        }

        $interruption = new TurnInterruption();
        $watcher = null;

        if ($interrupt) {
            $directory = $this->directory;
            $watcher = EventLoop::repeat(0.001, static function (string $id) use ($directory, $interruption): void {
                if (is_file($directory . '/first/first.started')) {
                    EventLoop::cancel($id);
                    $interruption->request();
                }
            });
        }

        $failure = null;
        $runner = new TurnRunner(new ConversationView(new VirtualTerminal(), 'Neuron AI', 'Conversation'));
        EventLoop::queue(static function () use ($runner, $agent, $interruption, &$failure): void {
            try {
                $runner->run($agent, 'First question.', $interruption);
            } catch (Throwable $caught) {
                $failure = $caught;
            }
        });

        try {
            EventLoop::run();
        } finally {
            if ($watcher !== null) {
                EventLoop::cancel($watcher);
            }
        }

        $expectedMessage = $handlerThrows ? 'Host handler failed: first really failed.' : 'first really failed.';
        $expectedType = $handlerThrows ? LogicException::class : RuntimeException::class;

        if ($interrupt) {
            self::assertNull($failure);
            $messages = $agent->getChatHistory()->getMessages();
            self::assertInstanceOf(ToolResultMessage::class, $messages[2]);
            self::assertSame([
                'neuron_tui' => 'tool_outcome',
                'status' => 'failed',
                'error_type' => RuntimeException::class,
                'message' => 'first really failed.',
            ], json_decode($messages[2]->getTools()[0]->getResult(), true, flags: JSON_THROW_ON_ERROR));
            self::assertSame('second really completed.', $messages[2]->getTools()[1]->getResult());
            self::assertSame('third really completed.', $messages[2]->getTools()[2]->getResult());
        } else {
            self::assertInstanceOf($expectedType, $failure);
            self::assertSame($expectedMessage, $failure->getMessage());
        }

        self::assertSame($handlerThrows ? 1 : 0, $handled);
        $provider->assertCallCount(1);
        $node = $agent->getNodeForEvent(ToolCallEvent::class);
        self::assertInstanceOf(ParallelToolNode::class, $node);
        $inspection = new class extends ParallelToolNode {
            public function handlerOf(ParallelToolNode $node): ?callable
            {
                return $node->errorHandler;
            }
        };
        self::assertSame($handler, $inspection->handlerOf($node));

        foreach (['first', 'second', 'third'] as $name) {
            self::assertSame('once', file_get_contents($this->directory . '/first/' . $name . '.settled'));
        }

        // Run Neuron directly with the same composed Agent. The original
        // unhandled/throwing handler must still throw, with no TUI adapter left.
        $rawFailure = null;

        try {
            iterator_to_array($agent->stream(new UserMessage('Second question.'))->events());
        } catch (Throwable $caught) {
            $rawFailure = $caught;
        }

        self::assertInstanceOf($expectedType, $rawFailure);
        self::assertSame($expectedMessage, $rawFailure->getMessage());
        self::assertSame($handlerThrows ? 2 : 0, $handled);
        $provider->assertCallCount(2);
    }

    public function testInterruptionBeforeParallelExecutionStartsSkipsEveryAnnouncedCall(): void
    {
        $interruption = new TurnInterruption();
        $call = $this->batch('unstarted');
        $provider = new class($interruption, $call) extends FakeAIProvider {
            public function __construct(private readonly TurnInterruption $interruption, ToolCallMessage $call)
            {
                parent::__construct($call);
            }

            protected function streamChunks(Message $response): Generator
            {
                yield from parent::streamChunks($response);
                $this->interruption->request();

                return $response;
            }
        };
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $agent->parallelToolCalls(true);
        $runner = new TurnRunner(new ConversationView(new VirtualTerminal(), 'Neuron AI', 'Conversation'));
        EventLoop::queue(static fn () => $runner->run($agent, 'Question.', $interruption));
        EventLoop::run();

        self::assertSame([], glob($this->directory . '/unstarted/*.started'));
        $messages = $agent->getChatHistory()->getMessages();
        self::assertCount(3, $messages);
        self::assertInstanceOf(ToolResultMessage::class, $messages[2]);
        self::assertCount(3, $messages[2]->getTools());

        foreach ($messages[2]->getTools() as $tool) {
            self::assertStringContainsString('"status":"not_executed"', $tool->getResult());
        }

        $provider->assertCallCount(1);
    }

    public function testEarlierParallelAnnouncementsDoNotMeanThoseToolsHaveStarted(): void
    {
        $interruption = new TurnInterruption();
        $provider = new FakeAIProvider($this->batch('announced'));
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $agent->parallelToolCalls(true);
        $observer = new class($interruption) implements ObserverInterface {
            public int $announced = 0;

            public function __construct(private readonly TurnInterruption $interruption)
            {
            }

            public function onEvent(string $event, object $source, mixed $data = null, ?string $branchId = null): void
            {
                if ($event === 'tool-calling' && ++$this->announced === 2) {
                    $this->interruption->request();
                }
            }
        };
        $agent->observe($observer);
        $runner = new TurnRunner(new ConversationView(new VirtualTerminal(), 'Neuron AI', 'Conversation'));
        EventLoop::queue(static fn () => $runner->run($agent, 'Question.', $interruption));
        EventLoop::run();

        self::assertSame(2, $observer->announced);
        self::assertSame([], glob($this->directory . '/announced/*.started'));
        $messages = $agent->getChatHistory()->getMessages();
        self::assertInstanceOf(ToolResultMessage::class, $messages[2]);

        foreach ($messages[2]->getTools() as $tool) {
            self::assertStringContainsString('"status":"not_executed"', $tool->getResult());
        }

        $provider->assertCallCount(1);
    }

    private function batch(string $name): ToolCallMessage
    {
        $directory = $this->directory . '/' . $name;
        mkdir($directory);

        return new ToolCallMessage(tools: [
            new ParallelBatchTool('first', $directory, true),
            new ParallelBatchTool('second', $directory),
            new ParallelBatchTool('third', $directory),
        ]);
    }
}
