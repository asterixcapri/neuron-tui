<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Http;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\FileChatHistory;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\Gemini\Gemini;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronAI\Tools\Tool;
use NeuronTui\Http\ResponseStop;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResponseStopTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function textProviders(): iterable
    {
        yield 'OpenAI Chat Completions' => ['openai', self::openaiText()];
        yield 'OpenAI Responses' => ['responses', implode("\n\n", [
            'data: {"type":"response.output_item.added","item":{"id":"msg-1","type":"message","content":[]}}',
            'data: {"type":"response.output_text.delta","item_id":"msg-1","delta":"Partial"}',
            'data: {"type":"response.output_text.delta","item_id":"msg-1","delta":" hidden"}',
        ]) . "\n\n"];
        yield 'Anthropic' => ['anthropic', implode("\n\n", [
            'data: {"type":"message_start","message":{"id":"msg-1","usage":{"input_tokens":10,"output_tokens":0}}}',
            'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}',
            'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Partial"}}',
            'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":" hidden"}}',
        ]) . "\n\n"];
        yield 'Gemini' => ['gemini', '[{"candidates":[{"content":{"parts":[{"text":"Partial"}]}}]},{"candidates":[{"content":{"parts":[{"text":" hidden"}]},"finishReason":"STOP"}]}]'];
    }

    #[DataProvider('textProviders')]
    public function testNeuronPersistsThePartialAndAgentStepsWithoutClientHistoryWrites(string $name, string $body): void
    {
        $stop = new ResponseStop();
        $stream = new FixtureStream($body);
        $client = new FixtureHttpClient([$stream, new FixtureStream($body)]);
        $http = $stop->httpClient($client);
        $provider = match ($name) {
            'openai' => new OpenAI('fixture-key', 'fixture-model', httpClient: $http),
            'responses' => new OpenAIResponses('fixture-key', 'fixture-model', httpClient: $http),
            'anthropic' => new Anthropic('fixture-key', 'fixture-model', httpClient: $http),
            'gemini' => new Gemini('fixture-key', 'fixture-model', httpClient: $http),
            default => throw new \LogicException('Unknown fixture provider'),
        };
        $agent = $this->agent($provider);
        $key = 'http-stop-' . bin2hex(random_bytes(8));
        $history = new FileChatHistory(sys_get_temp_dir(), $key);
        $agent->setChatHistory($history);

        try {
            $stop->begin();
            $handler = $agent->stream(new UserMessage('Question'));
            foreach ($handler->events() as $chunk) {
                if ($chunk instanceof TextChunk) {
                    self::assertTrue($stop->request());
                    self::assertFalse($stop->request());
                }
            }

            self::assertTrue($stop->finish());
            self::assertSame(1, $stream->closes);
            self::assertSame('Partial', $handler->getMessage()->getContent());
            self::assertNull($handler->getMessage()->getMetadata('stop_reason'));
            self::assertSame($history, $agent->getChatHistory());
            self::assertSame(['Question', 'Partial'], array_map(static fn (Message $message): ?string => $message->getContent(), $history->getMessages()));
            self::assertCount(2, $agent->resolveState()->getSteps());

            $reloaded = new FileChatHistory(sys_get_temp_dir(), $key);
            self::assertSame('Partial', $reloaded->getLastMessage()->getContent());
            $agent->setChatHistory($reloaded);
            $stop->begin();
            foreach ($agent->stream(new UserMessage('Continue'))->events() as $chunk) {
            }
            self::assertFalse($stop->finish());
            self::assertCount(4, $reloaded->getMessages());
            self::assertSame('Partial hidden', $reloaded->getLastMessage()->getContent());
            self::assertCount(2, $client->requests);
        } finally {
            $stop->finish();
            $history->flushAll();
        }
    }

    public function testStoppingBeforeTextUsesNeuronsEmptyAssistantAndDoesNotInventAMarker(): void
    {
        $stop = new ResponseStop();
        $client = new FixtureHttpClient([new FixtureStream(self::openaiText())]);
        $agent = $this->agent(new OpenAI('fixture-key', 'fixture-model', httpClient: $stop->httpClient($client)));
        $stop->begin();
        $stop->request();

        foreach ($agent->stream(new UserMessage('Question'))->events() as $chunk) {
            self::fail('No text should be emitted before the requested EOF.');
        }

        self::assertTrue($stop->finish());
        self::assertCount(2, $agent->getChatHistory()->getMessages());
        $last = $agent->getChatHistory()->getLastMessage();
        self::assertInstanceOf(Message::class, $last);
        self::assertNull($last->getContent());
        self::assertNull($last->getMetadata('stop_reason'));
        self::assertCount(1, $client->requests);
    }

    public function testTransportStopDoesNotSkipToolsOrPreventTheFollowingHttpRequest(): void
    {
        $stop = new ResponseStop();
        $executed = [];
        $first = (new Tool('first'))->setCallable(static function () use ($stop, &$executed): string {
            $executed[] = 'first';
            $stop->request();

            return 'First result';
        });
        $second = (new Tool('second'))->setCallable(static function () use (&$executed): string {
            $executed[] = 'second';

            return 'Second result';
        });
        $body = 'data: {"id":"calls","choices":[{"index":0,"delta":{"tool_calls":[{"index":0,"id":"one","type":"function","function":{"name":"first","arguments":"{}"}},{"index":1,"id":"two","type":"function","function":{"name":"second","arguments":"{}"}}]},"finish_reason":"tool_calls"}]}' . "\n\n";
        $client = new FixtureHttpClient([new FixtureStream($body), new FixtureStream(self::openaiText())]);
        $agent = $this->agent(new OpenAI('fixture-key', 'fixture-model', httpClient: $stop->httpClient($client)));
        $agent->addTool($first)->addTool($second);
        $stop->begin();

        foreach ($agent->stream(new UserMessage('Run tools'))->events() as $chunk) {
        }

        self::assertTrue($stop->finish());
        self::assertSame(['first', 'second'], $executed);
        self::assertCount(2, $client->requests);
        $messages = $agent->getChatHistory()->getMessages();
        self::assertCount(4, $messages);
        self::assertInstanceOf(\NeuronAI\Chat\Messages\ToolResultMessage::class, $messages[2]);
        self::assertSame('First result', $messages[2]->getTools()[0]->getResult());
        self::assertSame('Second result', $messages[2]->getTools()[1]->getResult());
    }

    public function testConfigurationAndNonStreamingRequestsPassThrough(): void
    {
        $stop = new ResponseStop();
        $inner = new FixtureHttpClient([]);
        $client = $stop->httpClient($inner)->withBaseUri('https://fixture.invalid')->withHeaders(['X-Test' => 'preserved'])->withTimeout(12.5);
        $request = HttpRequest::post('chat', ['message' => 'hello']);

        self::assertSame('normal request', $client->request($request)->body);
        self::assertSame('https://fixture.invalid', $inner->baseUri);
        self::assertSame(['X-Test' => 'preserved'], $inner->headers);
        self::assertSame(12.5, $inner->timeout);
        self::assertSame([$request], $inner->requests);
        self::assertFalse($stop->request());
    }

    public function testOldStreamsCannotConsumeAnotherTurnsRequest(): void
    {
        $stop = new ResponseStop();
        $old = new FixtureStream('old');
        $fresh = new FixtureStream('fresh');
        $client = $stop->httpClient(new FixtureHttpClient([$old, $fresh]));
        $stop->begin();
        $oldStream = $client->stream(HttpRequest::post('old'));
        self::assertFalse($stop->finish());
        $stop->begin();
        $newStream = $client->stream(HttpRequest::post('new'));
        $stop->request();

        self::assertFalse($oldStream->eof());
        self::assertTrue($newStream->eof());
        self::assertTrue($newStream->eof());
        self::assertSame('', $newStream->readLine());
        self::assertSame('', $newStream->read(1));
        self::assertSame(0, $old->closes);
        self::assertSame(1, $fresh->closes);
        self::assertTrue($stop->finish());
    }

    public function testNaturalEofWinsOverAnUnappliedRequest(): void
    {
        $stop = new ResponseStop();
        $empty = new FixtureStream('');
        $stop->begin();
        $stream = $stop->httpClient(new FixtureHttpClient([$empty]))->stream(HttpRequest::post('empty'));
        $stop->request();

        self::assertTrue($stream->eof());
        self::assertSame(0, $empty->closes);
        self::assertFalse($stop->finish());
    }

    public static function openaiText(): string
    {
        return 'data: {"id":"msg-1","choices":[{"index":0,"delta":{"content":"Partial"},"finish_reason":null}]}' . "\n\n"
            . 'data: {"id":"msg-1","choices":[{"index":0,"delta":{"content":" hidden"},"finish_reason":"stop"}]}' . "\n\n";
    }

    private function agent(AIProviderInterface $provider): Agent
    {
        $agent = new Agent();
        $agent->setAiProvider($provider);

        return $agent;
    }
}
