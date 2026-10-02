<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Turn;

use Generator;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Testing\FakeAIProvider;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronTui\Tui;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use RuntimeException;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

use function Amp\delay;
use function array_shift;

final class TurnSchedulerTitleTest extends TestCase
{
    public function testTuiStartsGenerationAfterCompletedTurnsWithoutCallerConfiguration(): void
    {
        $requests = new TurnTitleRequests(['{"title":null}', '{"title":"Configurazione Redis"}']);
        $store = new SessionStore(new InMemoryStorage(), 'local');
        $session = $store->create();
        $agent = $this->agent($requests);
        $agent = ($session)->bindTo($agent);
        $terminal = new VirtualTerminal();
        EventLoop::queue(static fn() => $terminal->simulateInput("ciao\r"));
        EventLoop::delay(0.05, static fn() => $terminal->simulateInput("Configuriamo Redis\r"));
        EventLoop::delay(0.1, static fn() => $terminal->simulateInput("Grazie\r"));
        EventLoop::delay(0.2, static fn() => $terminal->simulateInput("\x03"));

        Tui::make($agent)
            ->setSessionStore($store)
            ->setSession($session)
            ->setTerminal($terminal)
            ->run();

        self::assertSame('Configurazione Redis', $session->getTitle());
        self::assertSame(2, $requests->count);
        self::assertCount(6, $agent->getChatHistory()->getMessages());
        self::assertSame('Configurazione Redis', $store->summaries()[0]->title);
    }

    public function testTitleFailureDoesNotShowAWarningOrFailTheTurn(): void
    {
        $requests = new TurnTitleRequests([]);
        $store = new SessionStore(new InMemoryStorage(), 'local');
        $session = $store->create();
        $agent = $this->agent($requests);
        $agent = ($session)->bindTo($agent);
        $terminal = new VirtualTerminal();
        EventLoop::queue(static fn() => $terminal->simulateInput("A subject\r"));
        EventLoop::delay(0.1, static fn() => $terminal->simulateInput("\x03"));

        Tui::make($agent)
            ->setSessionStore($store)
            ->setSession($session)
            ->setTerminal($terminal)
            ->run();

        self::assertNull($session->getTitle());
        self::assertSame(1, $requests->count);
        self::assertCount(2, $agent->getChatHistory()->getMessages());
        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString('Ciao!', $display);
        self::assertStringNotContainsString('Session title generation failed', $display);
        self::assertStringNotContainsString('Title provider unavailable', $display);
    }

    public function testAFailedTurnDoesNotStartTitleGeneration(): void
    {
        $requests = new TurnTitleRequests(['{"title":"Unused"}']);
        $requests->failTurn = true;
        $store = new SessionStore(new InMemoryStorage(), 'local');
        $session = $store->create();
        $agent = $this->agent($requests);
        $agent = ($session)->bindTo($agent);
        $terminal = new VirtualTerminal();
        EventLoop::queue(static fn() => $terminal->simulateInput("A subject\r"));
        EventLoop::delay(0.1, static fn() => $terminal->simulateInput("\x03"));

        Tui::make($agent)
            ->setSessionStore($store)
            ->setSession($session)
            ->setTerminal($terminal)
            ->run();

        self::assertNull($session->getTitle());
        self::assertSame(0, $requests->count);
    }

    public function testSuccessfulTurnsDoNotOverlapPendingTitleRequests(): void
    {
        $requests = new TurnTitleRequests(['{"title":"Automatic title"}']);
        $requests->titleDelay = 0.15;
        $store = new SessionStore(new InMemoryStorage(), 'local');
        $session = $store->create();
        $agent = $session->bindTo($this->agent($requests));
        $terminal = new VirtualTerminal();
        EventLoop::queue(static fn() => $terminal->simulateInput("First subject\r"));
        EventLoop::delay(0.04, static fn() => $terminal->simulateInput("More detail\r"));
        EventLoop::delay(0.08, static fn() => $terminal->simulateInput("Last detail\r"));
        EventLoop::delay(0.25, static fn() => $terminal->simulateInput("\x03"));

        Tui::make($agent)
            ->setSessionStore($store)
            ->setSession($session)
            ->setTerminal($terminal)
            ->run();

        self::assertSame(1, $requests->count);
        self::assertSame('Automatic title', $session->getTitle());
        self::assertCount(6, $agent->getChatHistory()->getMessages());
    }

    public function testATitleErrorAllowsGenerationAfterTheNextSuccessfulTurn(): void
    {
        $requests = new TurnTitleRequests(['{"title":"Recovered title"}']);
        $requests->titleFailures = 1;
        $store = new SessionStore(new InMemoryStorage(), 'local');
        $session = $store->create();
        $agent = $session->bindTo($this->agent($requests));
        $terminal = new VirtualTerminal();
        EventLoop::queue(static fn() => $terminal->simulateInput("A subject\r"));
        EventLoop::delay(0.05, static fn() => $terminal->simulateInput("More detail\r"));
        EventLoop::delay(0.15, static fn() => $terminal->simulateInput("\x03"));

        Tui::make($agent)
            ->setSessionStore($store)
            ->setSession($session)
            ->setTerminal($terminal)
            ->run();

        self::assertSame(2, $requests->count);
        self::assertSame('Recovered title', $session->getTitle());
        self::assertCount(4, $agent->getChatHistory()->getMessages());
    }

    private function agent(TurnTitleRequests $requests): Agent
    {
        $provider = new class ($requests) extends FakeAIProvider {
            public function __construct(private readonly TurnTitleRequests $requests)
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

            public function structured(array|Message $messages, string $class, array $response_schema): ProviderResponse
            {
                ++$this->requests->count;
                if ($this->requests->titleDelay > 0) {
                    delay($this->requests->titleDelay);
                }
                if ($this->requests->titleFailures > 0) {
                    --$this->requests->titleFailures;
                    throw new RuntimeException('Title provider unavailable.');
                }
                $response = array_shift($this->requests->responses);
                if ($response === null) {
                    throw new RuntimeException('Title provider unavailable.');
                }

                return new ProviderResponse(message: new AssistantMessage($response));
            }
        };

        $agent = (new Agent())->setThreadId('test-thread');
        $agent->setAiProvider($provider);

        return $agent;
    }
}

final class TurnTitleRequests
{
    public int $count = 0;

    public bool $failTurn = false;

    public float $titleDelay = 0;

    public int $titleFailures = 0;

    /** @param list<string> $responses */
    public function __construct(public array $responses) {}
}
