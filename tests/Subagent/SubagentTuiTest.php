<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Subagent;

use Generator;
use LogicException;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolInterface;
use NeuronTui\Subagent\SubagentToolkit;
use NeuronTui\Tests\Subagent\Fixture\ProcessWorkerAgent;
use NeuronTui\Tui;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class SubagentTuiTest extends TestCase
{
    public function testRealToolkitCompletesANonBlockingMultiTurnConversation(): void
    {
        $events = tempnam(sys_get_temp_dir(), 'neuron-e2e-events-');
        $release = tempnam(sys_get_temp_dir(), 'neuron-e2e-release-');

        if ($events === false || $release === false) {
            throw new \RuntimeException('Unable to create process test files.');
        }

        unlink($release);
        putenv("NEURON_TUI_PROCESS_TEST_EVENTS={$events}");
        putenv("NEURON_TUI_PROCESS_TEST_RELEASE={$release}");

        try {
            $provider = new SubagentHostProvider();
            $agent = new Agent();
            $agent->addTool(new SubagentToolkit(ProcessWorkerAgent::class, 1));
            $agent->setAiProvider($provider);
            $terminal = new VirtualTerminal(rows: 40);
            $releasedAfterParentWasReady = false;

            EventLoop::queue(
                static fn () => $terminal->simulateInput("Delegate this\r"),
            );
            $watcher = EventLoop::repeat(
                0.01,
                static function () use (
                    $terminal,
                    $release,
                    &$releasedAfterParentWasReady,
                ): void {
                    $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());

                    if (
                        !$releasedAfterParentWasReady
                        && str_contains($display, 'Delegation started.')
                        && str_contains($display, 'ready · Enter sends')
                    ) {
                        $releasedAfterParentWasReady = true;
                        touch($release);
                    }

                    if (str_contains($display, 'Parent interpreted continuation:')) {
                        $terminal->simulateInput("\x03");
                    }
                },
            );
            $timeout = EventLoop::delay(
                8,
                static fn () => $terminal->simulateInput("\x03"),
            );

            (new Tui($agent, $terminal))->run();

            EventLoop::cancel($watcher);
            EventLoop::cancel($timeout);
            $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());

            self::assertTrue(
                $releasedAfterParentWasReady,
                'The parent Turn did not finish while child work was blocked. '
                    .$display,
            );
            self::assertCount(2, $provider->replyIds);
            self::assertSame($provider->replyIds[0], $provider->replyIds[1]);
            self::assertStringContainsString(
                'Parent interpreted continuation:',
                $display,
            );
            self::assertStringContainsString('follow-up child task', $display);
            self::assertStringContainsString('wait:e2e', $display);
        } finally {
            putenv('NEURON_TUI_PROCESS_TEST_EVENTS');
            putenv('NEURON_TUI_PROCESS_TEST_RELEASE');
            @unlink($events);
            @unlink($release);
        }
    }
}

final class SubagentHostProvider extends FakeAIProvider
{
    private int $turn = 0;

    /** @var list<string> */
    public array $replyIds = [];

    public function stream(Message ...$messages): Generator
    {
        ++$this->turn;

        $response = match ($this->turn) {
            1 => new ToolCallMessage(tools: [
                $this->tool('subagent')
                    ->setCallId('start-child')
                    ->setInputs(['task' => 'wait:e2e']),
            ]),
            2 => new AssistantMessage('Delegation started.'),
            3 => new ToolCallMessage(tools: [
                $this->tool('subagent_send')
                    ->setCallId('continue-child')
                    ->setInputs([
                        'subagent_id' => $this->replyId($messages),
                        'message' => 'follow-up child task',
                    ]),
            ]),
            4 => new AssistantMessage('Asked the same child to continue.'),
            5 => new AssistantMessage(
                'Parent interpreted continuation: '.$this->recordReply($messages),
            ),
            default => throw new LogicException('Unexpected Host Agent Turn.'),
        };

        $contents = $response->getContent();

        if (is_string($contents)) {
            yield new TextChunk('host-'.$this->turn, $contents);
        }

        return $response;
    }

    private function tool(string $name): ToolInterface
    {
        foreach ($this->tools as $tool) {
            if ($tool instanceof ToolInterface && $tool->getName() === $name) {
                return $tool;
            }
        }

        throw new LogicException("Missing {$name} tool.");
    }

    /** @param array<array-key, Message> $messages */
    private function replyId(array $messages): string
    {
        $reply = $this->lastReply($messages);

        if (preg_match('/Subagent ID: ([a-f0-9]{32})/', $reply, $matches) !== 1) {
            throw new LogicException('The child Reply has no ID.');
        }

        $this->replyIds[] = $matches[1];

        return $matches[1];
    }

    /** @param array<array-key, Message> $messages */
    private function recordReply(array $messages): string
    {
        $reply = $this->lastReply($messages);

        if (preg_match('/Subagent ID: ([a-f0-9]{32})/', $reply, $matches) !== 1) {
            throw new LogicException('The child Reply has no ID.');
        }

        $this->replyIds[] = $matches[1];

        return $reply;
    }

    /** @param array<array-key, Message> $messages */
    private function lastReply(array $messages): string
    {
        foreach (array_reverse($messages) as $message) {
            $contents = $message->getContent() ?? '';

            if (str_starts_with($contents, 'Subagent reply')) {
                return $contents;
            }
        }

        throw new LogicException('No child Reply reached the Host Agent.');
    }
}
