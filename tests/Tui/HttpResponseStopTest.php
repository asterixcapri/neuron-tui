<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Tui;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\StoppableHttpClient;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronChatCore\Command\Commands;
use NeuronChatCore\Command\HelpCommand;
use NeuronChatCore\Conversation\ConversationRuntime as CoreRuntime;
use NeuronChatCore\Interruption\StopSignal;
use NeuronChatCore\Session\SessionStore;
use NeuronChatCore\Storage\InMemoryStorage;
use NeuronTui\Tests\Http\FixtureHttpClient;
use NeuronTui\Tests\Http\FixtureStream;
use NeuronTui\Tui;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use RuntimeException;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

use function Amp\delay;
use function array_filter;
use function array_map;
use function is_array;
use function json_encode;
use function str_contains;

use const JSON_THROW_ON_ERROR;

final class HttpResponseStopTest extends TestCase
{
    public function testEscapeUsesHttpEofWhilePreservingFifoAndDraftCursor(): void
    {
        $terminal = new VirtualTerminal(columns: 140, rows: 40);
        $stopSignal = new StopSignal(new InMemoryStorage(), 'conversation');
        // The Host clears a leftover signal before starting a new Turn.
        $stopSignal->request();
        $first = new FixtureStream($this->body('Partial', complete: false) . $this->body(' Hidden'), static function (string $line) use ($terminal): void {
            if (str_contains($line, 'Partial')) {
                $terminal->simulateInput("Second\rThird\rDraft\x1b[D\x1b");
                delay(0.08);
            }
        });
        $client = new FixtureHttpClient([$first, new FixtureStream($this->body('Second answer')), new FixtureStream($this->body('Third answer')), new FixtureStream($this->body('Draft answer'))]);
        $agent = $this->agent($client, $stopSignal);
        $history = $agent->getChatHistory();
        EventLoop::queue(static fn() => $terminal->simulateInput("First\r"));
        EventLoop::delay(0.18, static fn() => $terminal->simulateInput("!\r"));
        EventLoop::delay(0.3, static fn() => $terminal->simulateInput("\x03"));

        $tui = new Tui(new CoreRuntime($agent, new SessionStore(new InMemoryStorage(), 'local'), stopSignal: $stopSignal), $terminal);
        $tui->run();

        self::assertNotSame($history->getThreadId(), $tui->agent()->getChatHistory()->getThreadId());
        self::assertSame(['First', 'Partial', 'Second', 'Second answer', 'Third', 'Third answer', 'Draf!t', 'Draft answer'], array_map(static fn(Message $message): ?string => $message->getContent(), $tui->agent()->getChatHistory()->getMessages()));
        self::assertSame('stopped', $tui->agent()->getChatHistory()->getMessages()[1]->getMetadata('stop_reason'));
        self::assertCount(4, array_filter($client->requests, static fn(HttpRequest $request): bool => is_array($request->body) && ($request->body['stream'] ?? false) === true));
        self::assertSame(1, $first->closes);
        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString('Stopped', $display);
        self::assertStringContainsString('Stop requested', $display);
        self::assertStringNotContainsString('Hidden', $display);
        self::assertFalse($stopSignal->isRequested());
    }

    public function testSuggestionsConsumeEscapeWithoutRequestingHttpStop(): void
    {
        $terminal = new VirtualTerminal(columns: 140, rows: 30);
        $stopSignal = new StopSignal(new InMemoryStorage(), 'conversation');
        $stream = new FixtureStream($this->body('Complete'), static function (string $line) use ($terminal): void {
            if (str_contains($line, 'Complete')) {
                $terminal->simulateInput("/h\x1b");
                delay(0.04);
            }
        });
        $agent = $this->agent(new FixtureHttpClient([$stream]), $stopSignal);
        EventLoop::queue(static fn() => $terminal->simulateInput("Question\r"));
        EventLoop::delay(0.15, static fn() => $terminal->simulateInput("\x03"));

        $tui = Tui::make(new CoreRuntime($agent, new SessionStore(new InMemoryStorage(), 'local'), stopSignal: $stopSignal), $terminal, (new Commands())->addCommand(new HelpCommand()));
        $tui->run();

        self::assertSame(0, $stream->closes);
        self::assertStringNotContainsString('Stop requested', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
        self::assertCount(2, $tui->agent()->getChatHistory()->getMessages());
    }

    public function testTransportFailureRemainsAnErrorAndTheNextTurnCanRun(): void
    {
        $terminal = new VirtualTerminal(columns: 140, rows: 30);
        $stopSignal = new StopSignal(new InMemoryStorage(), 'conversation');
        $stream = new FixtureStream($this->body('Failure'), static function () use ($terminal): never {
            $terminal->simulateInput("Next\r\x1b");
            delay(0.04);

            throw new RuntimeException('Transport failed');
        });
        $client = new FixtureHttpClient([$stream, new FixtureStream($this->body('Next answer'))]);
        $agent = $this->agent($client, $stopSignal);
        EventLoop::queue(static fn() => $terminal->simulateInput("Question\r"));
        EventLoop::delay(0.18, static fn() => $terminal->simulateInput("\x03"));

        $tui = Tui::make(new CoreRuntime($agent, new SessionStore(new InMemoryStorage(), 'local'), stopSignal: $stopSignal), $terminal);
        $tui->run();

        self::assertSame(['Next', 'Next answer'], array_map(static fn(Message $message): ?string => $message->getContent(), $tui->agent()->getChatHistory()->getMessages()));
        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString('RuntimeException: Transport failed', $display);
        self::assertStringNotContainsString('Stopped', $display);
        self::assertCount(2, array_filter($client->requests, static fn(HttpRequest $request): bool => is_array($request->body) && ($request->body['stream'] ?? false) === true));
    }

    private function agent(FixtureHttpClient $client, StopSignal $stopSignal): Agent
    {
        $agent = (new Agent())->setThreadId('test-thread');
        $agent->setAiProvider(new OpenAI('fixture-key', 'fixture-model', httpClient: new StoppableHttpClient(client: $client, shouldStop: $stopSignal->stopCallback(onPoll: static function (): void { delay(0); }))));

        return $agent;
    }

    private function body(string $text, bool $complete = true): string
    {
        return 'data: ' . json_encode(['id' => 'msg-1', 'choices' => [['index' => 0, 'delta' => ['content' => $text], 'finish_reason' => $complete ? 'stop' : null]]], JSON_THROW_ON_ERROR) . "\n\n";
    }
}
