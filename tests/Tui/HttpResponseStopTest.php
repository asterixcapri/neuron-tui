<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Tui;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\HelpCommand;
use NeuronTui\Http\ResponseStop;
use NeuronTui\Tests\Http\FixtureHttpClient;
use NeuronTui\Tests\Http\FixtureStream;
use NeuronTui\Tui;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use RuntimeException;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

use function Amp\delay;

final class HttpResponseStopTest extends TestCase
{
    public function testEscapeUsesHttpEofWhilePreservingFifoAndDraftCursor(): void
    {
        $terminal = new VirtualTerminal(columns: 140, rows: 40);
        $stop = new ResponseStop();
        $first = new FixtureStream($this->body('Partial') . $this->body(' Hidden'), static function (string $line) use ($terminal): void {
            if (str_contains($line, 'Partial')) {
                $terminal->simulateInput("Second\rThird\rDraft\x1b[D\x1b");
                delay(0.08);
            }
        });
        $client = new FixtureHttpClient([$first, new FixtureStream($this->body('Second answer')), new FixtureStream($this->body('Third answer')), new FixtureStream($this->body('Draft answer'))]);
        $agent = $this->agent($client, $stop);
        $history = $agent->getChatHistory();
        EventLoop::queue(static fn () => $terminal->simulateInput("First\r"));
        EventLoop::delay(0.18, static fn () => $terminal->simulateInput("!\r"));
        EventLoop::delay(0.3, static fn () => $terminal->simulateInput("\x03"));

        Tui::make($agent, $terminal)->setResponseStop($stop)->run();

        self::assertSame($history, $agent->getChatHistory());
        self::assertSame(['First', 'Partial', 'Second', 'Second answer', 'Third', 'Third answer', 'Draf!t', 'Draft answer'], array_map(static fn (Message $message): ?string => $message->getContent(), $history->getMessages()));
        self::assertNull($history->getMessages()[1]->getMetadata('stop_reason'));
        self::assertCount(4, $client->requests);
        self::assertSame(1, $first->closes);
        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString('HTTP response stopped.', $display);
        self::assertStringContainsString('Stop requested', $display);
        self::assertStringNotContainsString('Hidden', $display);
        self::assertFalse($stop->request());
    }

    public function testSuggestionsConsumeEscapeWithoutRequestingHttpStop(): void
    {
        $terminal = new VirtualTerminal(columns: 140, rows: 30);
        $stop = new ResponseStop();
        $stream = new FixtureStream($this->body('Complete'), static function (string $line) use ($terminal): void {
            if (str_contains($line, 'Complete')) {
                $terminal->simulateInput("/h\x1b");
                delay(0.04);
            }
        });
        $agent = $this->agent(new FixtureHttpClient([$stream]), $stop);
        EventLoop::queue(static fn () => $terminal->simulateInput("Question\r"));
        EventLoop::delay(0.15, static fn () => $terminal->simulateInput("\x03"));

        Tui::make($agent, $terminal, new Commands(new HelpCommand()))->setResponseStop($stop)->run();

        self::assertSame(0, $stream->closes);
        self::assertStringNotContainsString('Stop requested', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
        self::assertCount(2, $agent->getChatHistory()->getMessages());
    }

    public function testTransportFailureRemainsAnErrorAndTheNextTurnCanRun(): void
    {
        $terminal = new VirtualTerminal(columns: 140, rows: 30);
        $stop = new ResponseStop();
        $stream = new FixtureStream($this->body('Failure'), static function () use ($terminal): never {
            $terminal->simulateInput("Next\r\x1b");
            delay(0.04);

            throw new RuntimeException('Transport failed');
        });
        $client = new FixtureHttpClient([$stream, new FixtureStream($this->body('Next answer'))]);
        $agent = $this->agent($client, $stop);
        EventLoop::queue(static fn () => $terminal->simulateInput("Question\r"));
        EventLoop::delay(0.18, static fn () => $terminal->simulateInput("\x03"));

        Tui::make($agent, $terminal)->setResponseStop($stop)->run();

        self::assertSame(['Next', 'Next answer'], array_map(static fn (Message $message): ?string => $message->getContent(), $agent->getChatHistory()->getMessages()));
        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString('RuntimeException: Transport failed', $display);
        self::assertStringNotContainsString('HTTP response stopped.', $display);
        self::assertCount(2, $client->requests);
    }

    private function agent(FixtureHttpClient $client, ResponseStop $stop): Agent
    {
        $agent = new Agent();
        $agent->setAiProvider(new OpenAI('fixture-key', 'fixture-model', httpClient: $stop->httpClient($client)));

        return $agent;
    }

    private function body(string $text): string
    {
        return 'data: ' . json_encode(['id' => 'msg-1', 'choices' => [['index' => 0, 'delta' => ['content' => $text], 'finish_reason' => null]]], JSON_THROW_ON_ERROR) . "\n\n";
    }
}
