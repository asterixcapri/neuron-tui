<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Session;

use Generator;
use InvalidArgumentException;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronTui\Session\SessionTitleGeneration;
use NeuronTui\Tui;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use RuntimeException;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

use function Amp\delay;

final class SessionTitleGenerationTest extends TestCase
{
    public function testNullIsRetriedAndASavedTitleStopsFurtherGeneration(): void
    {
        $requests = new TitleRequests(['{"title":null}', '{"title":"Configurazione Redis"}']);
        $agent = $this->agent($requests);
        $session = (new SessionStore(new InMemoryStorage(), 'local'))->create();
        $session->addMessage(new UserMessage('ciao, /caveman'));
        $session->addMessage(new AssistantMessage('Ciao!'));
        $generation = new SessionTitleGeneration();

        $generation->schedule($session, $agent);
        delay(0.01);
        self::assertNull($session->title());
        self::assertSame(1, $requests->count);

        $session->addMessage(new UserMessage('Aiutami a configurare Redis'));
        $original = $session->jsonSerialize();
        $generation->schedule($session, $agent);
        delay(0.01);
        self::assertSame('Configurazione Redis', $session->title());
        self::assertSame($original, $session->jsonSerialize());

        $generation->schedule($session, $agent);
        delay(0.01);
        self::assertSame(2, $requests->count);
        self::assertSame([], $agent->getChatHistory()->getMessages());
    }

    public function testConcurrentRequestsDoNotOverwriteAManualTitle(): void
    {
        $requests = new TitleRequests(['{"title":"Automatic title"}']);
        $agent = $this->agent($requests);
        $session = (new SessionStore(new InMemoryStorage(), 'local'))->create();
        $session->addMessage(new UserMessage('A subject'));
        $generation = new SessionTitleGeneration();
        $generation->schedule($session, $agent);
        $generation->schedule($session, $agent);
        $session->setTitle('Manual title');
        delay(0.01);

        self::assertSame(1, $requests->count);
        self::assertSame('1', $session->getMetadata()['titleGenerationAttempts']);
        self::assertSame('Manual title', $session->title());
    }

    public function testAnErrorLeavesTheTitleNullAndAllowsTheNextTurnToRetry(): void
    {
        $requests = new TitleRequests([]);
        $session = (new SessionStore(new InMemoryStorage(), 'local'))->create();
        $session->addMessage(new UserMessage('A subject'));
        $generation = new SessionTitleGeneration();
        $agent = $this->agent($requests);
        $generation->schedule($session, $agent);
        delay(0.01);

        self::assertNull($session->title());
        $requests->responses[] = '{"title":"Recovered title"}';
        $generation->schedule($session, $agent);
        delay(0.01);
        self::assertSame('Recovered title', $session->title());
    }

    public function testNullResultsStopAtTheDefaultLimitAcrossReloads(): void
    {
        $requests = new TitleRequests(['{"title":null}', '{"title":null}', '{"title":null}', '{"title":"Unused"}']);
        $storage = new InMemoryStorage();
        $store = new SessionStore($storage, 'local');
        $session = $store->create();
        $session->addMessage(new UserMessage('hello'));
        $agent = $this->agent($requests);

        for ($turn = 0; $turn < 4; ++$turn) {
            $reloaded = (new SessionStore($storage, 'local'))->read($session->getKey());
            self::assertNotNull($reloaded);
            (new SessionTitleGeneration())->schedule($reloaded, $agent);
            delay(0.01);
        }

        self::assertSame(3, $requests->count);
        self::assertSame('3', $session->getMetadata()['titleGenerationAttempts']);
        self::assertNull($session->title());
    }

    public function testErrorsCountTowardTheConfiguredLimit(): void
    {
        $requests = new TitleRequests([]);
        $session = (new SessionStore(new InMemoryStorage(), 'local'))->create();
        $session->addMessage(new UserMessage('A subject'));
        $generation = new SessionTitleGeneration(maxAttempts: 2);
        $agent = $this->agent($requests);

        for ($turn = 0; $turn < 3; ++$turn) {
            $generation->schedule($session, $agent);
            delay(0.01);
        }

        self::assertSame(2, $requests->count);
        self::assertSame('2', $session->getMetadata()['titleGenerationAttempts']);
        self::assertNull($session->title());
    }

    public function testInvalidAttemptMetadataDoesNotStartARequest(): void
    {
        $requests = new TitleRequests(['{"title":"Unused"}']);
        $session = (new SessionStore(new InMemoryStorage(), 'local'))->create();
        $session->addMessage(new UserMessage('A subject'));
        $session->setMetadata('titleGenerationAttempts', 'invalid');

        (new SessionTitleGeneration())->schedule($session, $this->agent($requests));
        delay(0.01);

        self::assertSame(0, $requests->count);
        self::assertSame('invalid', $session->getMetadata()['titleGenerationAttempts']);
    }

    public function testAttemptLimitMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SessionTitleGeneration(maxAttempts: 0);
    }

    public function testTuiStartsGenerationAfterCompletedTurnsWithoutCallerConfiguration(): void
    {
        $requests = new TitleRequests(['{"title":null}', '{"title":"Configurazione Redis"}']);
        $store = new SessionStore(new InMemoryStorage(), 'local');
        $session = $store->create();
        $agent = $this->agent($requests);
        $agent->setChatHistory($session);
        $terminal = new VirtualTerminal();
        EventLoop::queue(static fn () => $terminal->simulateInput("ciao\r"));
        EventLoop::delay(0.05, static fn () => $terminal->simulateInput("Configuriamo Redis\r"));
        EventLoop::delay(0.1, static fn () => $terminal->simulateInput("Grazie\r"));
        EventLoop::delay(0.2, static fn () => $terminal->simulateInput("\x03"));

        Tui::make($agent, $terminal, sessionStore: $store)->run();

        self::assertSame('Configurazione Redis', $session->title());
        self::assertSame(2, $requests->count);
        self::assertCount(6, $session->getMessages());
        self::assertSame('Configurazione Redis', $store->summaries()[0]->title);
    }

    public function testTitleFailureDoesNotShowAWarningOrFailTheTurn(): void
    {
        $requests = new TitleRequests([]);
        $store = new SessionStore(new InMemoryStorage(), 'local');
        $session = $store->create();
        $agent = $this->agent($requests);
        $agent->setChatHistory($session);
        $terminal = new VirtualTerminal();
        EventLoop::queue(static fn () => $terminal->simulateInput("A subject\r"));
        EventLoop::delay(0.1, static fn () => $terminal->simulateInput("\x03"));

        Tui::make($agent, $terminal, sessionStore: $store)->run();

        self::assertNull($session->title());
        self::assertSame(1, $requests->count);
        self::assertCount(2, $session->getMessages());
        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString('Ciao!', $display);
        self::assertStringNotContainsString('Session title generation failed', $display);
        self::assertStringNotContainsString('Title provider unavailable', $display);
    }

    public function testDeletedSessionsAreNotRecreatedByAPendingTitle(): void
    {
        $requests = new TitleRequests(['{"title":"Late title"}']);
        $store = new SessionStore(new InMemoryStorage(), 'local');
        $session = $store->create();
        $session->addMessage(new UserMessage('A subject'));
        $generation = new SessionTitleGeneration();
        $generation->schedule($session, $this->agent($requests));
        $store->delete($session->getKey());
        delay(0.01);

        self::assertNull($store->read($session->getKey()));
    }

    public function testAFailedTurnDoesNotStartTitleGeneration(): void
    {
        $requests = new TitleRequests(['{"title":"Unused"}']);
        $requests->failTurn = true;
        $store = new SessionStore(new InMemoryStorage(), 'local');
        $session = $store->create();
        $agent = $this->agent($requests);
        $agent->setChatHistory($session);
        $terminal = new VirtualTerminal();
        EventLoop::queue(static fn () => $terminal->simulateInput("A subject\r"));
        EventLoop::delay(0.1, static fn () => $terminal->simulateInput("\x03"));

        Tui::make($agent, $terminal, sessionStore: $store)->run();

        self::assertNull($session->title());
        self::assertSame(0, $requests->count);
    }

    private function agent(TitleRequests $requests): Agent
    {
        $provider = new class($requests) extends FakeAIProvider {
            public function __construct(private readonly TitleRequests $requests)
            {
                parent::__construct(new AssistantMessage('Ciao!'), new AssistantMessage('Redis setup'), new AssistantMessage('Done'));
            }

            public function stream(Message ...$messages): Generator
            {
                if ($this->requests->failTurn) {
                    throw new RuntimeException('Main turn failed.');
                }

                return yield from parent::stream(...$messages);
            }

            public function structured(array|Message $messages, string $class, array $response_schema): Message
            {
                ++$this->requests->count;
                $response = array_shift($this->requests->responses);
                if ($response === null) {
                    throw new RuntimeException('Title provider unavailable.');
                }

                return new AssistantMessage($response);
            }
        };

        $agent = new Agent();
        $agent->setAiProvider($provider);

        return $agent;
    }
}

final class TitleRequests
{
    public int $count = 0;

    public bool $failTurn = false;

    /** @param list<string> $responses */
    public function __construct(public array $responses)
    {
    }
}
