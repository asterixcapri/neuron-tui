<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Tui;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\Tool;
use NeuronTui\History\HistoryProjection;
use NeuronTui\History\ProjectedEntry;
use NeuronTui\History\ProjectedEntryKind;
use NeuronTui\Tui;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use RuntimeException;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

use function Amp\delay;

final class SequentialToolInterruptionTest extends TestCase
{
    /** @return iterable<string, array{bool, bool, bool}> */
    public static function interruptions(): iterable
    {
        yield 'cooperative with FIFO' => [true, false, true];
        yield 'cooperative failure with FIFO' => [true, true, true];
        yield 'synchronous buffered input' => [false, false, true];
        yield 'synchronous failure with buffered input' => [false, true, true];
        yield 'ready without waiting input' => [true, false, false];
    }

    #[DataProvider('interruptions')]
    public function testStartedWorkSettlesAndTheCompleteGroupPrecedesWaitingInput(bool $cooperative, bool $fails, bool $queued): void
    {
        $terminal = new VirtualTerminal(columns: 160, rows: 45);
        $runs = [0, 0, 0];
        $pending = '';
        $first = (new Tool('first'))->setCallId('first-id')->setInputs(['value' => 'one'])
            ->setCallable(static function () use ($terminal, $cooperative, $fails, $queued, &$runs, &$pending): string {
                ++$runs[0];
                // Input queued by a blocking tool cannot dispatch until the
                // runner yields; a cooperative tool can acknowledge it now.
                EventLoop::queue(static fn () => $terminal->simulateInput(($queued ? "Second question\rThird question\r" : '') . "\x1b\x1b\x1b"));

                if ($cooperative) {
                    delay(0.06);
                    $pending = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                }

                if ($fails) {
                    throw new RuntimeException('The first operation really failed.');
                }

                return 'The first operation really completed.';
            });
        $second = (new Tool('second'))->setCallId('second-id')->setInputs(['value' => 'two'])
            ->setCallable(static function () use (&$runs): string {
                ++$runs[1];

                return 'Unexpected second execution.';
            });
        $third = (new Tool('third'))->setCallId('third-id')->setInputs(['value' => 'three'])
            ->setCallable(static function () use (&$runs): string {
                ++$runs[2];

                return 'Unexpected third execution.';
            });
        $call = new ToolCallMessage('I will perform three operations.', [$first, $second, $third]);
        $provider = new FakeAIProvider($call, new AssistantMessage('Second answer.'), new AssistantMessage('Third answer.'));
        $agent = new Agent();
        $agent->setAiProvider($provider);
        EventLoop::queue(static fn () => $terminal->simulateInput("First question\r"));
        EventLoop::delay(0.3, static fn () => $terminal->simulateInput("\x03"));

        (new Tui($agent, terminal: $terminal))->run();

        self::assertSame([1, 0, 0], $runs);
        $messages = $agent->getChatHistory()->getMessages();
        self::assertCount($queued ? 7 : 3, $messages);
        self::assertSame($call, $messages[1]);
        self::assertInstanceOf(ToolResultMessage::class, $messages[2]);
        $results = $messages[2]->getTools();
        self::assertCount(3, $results);

        foreach ([$first, $second, $third] as $index => $original) {
            self::assertSame($original->getCallId(), $results[$index]->getCallId());
            self::assertSame($original->getName(), $results[$index]->getName());
            self::assertSame($original->getInputs(), $results[$index]->getInputs());
        }

        if ($fails) {
            self::assertSame([
                'neuron_tui' => 'tool_outcome',
                'status' => 'failed',
                'error_type' => RuntimeException::class,
                'message' => 'The first operation really failed.',
            ], json_decode($results[0]->getResult(), true, flags: JSON_THROW_ON_ERROR));
        } else {
            self::assertSame('The first operation really completed.', $results[0]->getResult());
        }

        foreach ([$results[1], $results[2]] as $skipped) {
            self::assertSame([
                'neuron_tui' => 'tool_outcome',
                'status' => 'not_executed',
                'reason' => 'turn_interrupted',
                'message' => 'Execution never began because the person interrupted the Turn.',
            ], json_decode($skipped->getResult(), true, flags: JSON_THROW_ON_ERROR));
        }

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString('Not executed · Turn interrupted', $display);
        self::assertStringContainsString($fails ? 'Failed in' : 'Done in', $display);
        self::assertStringContainsString($fails ? 'The first operation really failed.' : 'The first operation really completed.', $display);
        self::assertStringContainsString('ready · Enter sends', $display);
        self::assertStringNotContainsString('Unexpected', $display);
        $historicalTools = array_values(array_filter(
            (new HistoryProjection($messages))->entries(),
            static fn (ProjectedEntry $entry): bool => $entry->kind === ProjectedEntryKind::Tool,
        ));
        self::assertCount(3, $historicalTools);
        self::assertStringContainsString('Not executed · Turn interrupted', $historicalTools[1]->text);
        self::assertStringNotContainsString('Done', $historicalTools[1]->text);
        self::assertStringContainsString($fails ? 'Failed in' : 'Done in', $historicalTools[0]->text);
        self::assertCount(1, array_filter($messages, static fn (Message $message): bool => $message instanceof ToolResultMessage));

        if ($cooperative) {
            self::assertStringContainsString('Interruption requested · waiting for the current tool', $pending);
        }

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
