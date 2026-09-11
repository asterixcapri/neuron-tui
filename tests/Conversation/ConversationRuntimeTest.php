<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Conversation;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Testing\FakeAIProvider;
use NeuronTui\Conversation\ConversationRuntime;
use NeuronTui\Conversation\MessageForAgent;
use NeuronTui\View\ConversationView;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class ConversationRuntimeTest extends TestCase
{
    public function testNaturalCompletionBeforeEscapeWinsAndAdvancesOnlyOnce(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('First answer.'), new AssistantMessage('Next answer.'));
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $runtime = new ConversationRuntime($agent, new ConversationView(new VirtualTerminal(), 'Neuron AI', 'Conversation'));
        $runtime->submitMessage(new MessageForAgent('First question.'));
        $runtime->submitMessage(new MessageForAgent('Next question.'));
        $runtime->tick();
        EventLoop::run();

        // The Future completed, but the queue has not observed it yet.
        $runtime->requestInterruption();
        $runtime->requestInterruption();
        self::assertTrue($runtime->tick());
        self::assertTrue($runtime->tick());
        EventLoop::run();
        self::assertFalse($runtime->tick());
        self::assertFalse($runtime->tick());
        self::assertFalse($runtime->isBusy());

        $messages = $agent->getChatHistory()->getMessages();
        self::assertSame(['First question.', 'First answer.', 'Next question.', 'Next answer.'], array_map(
            static fn (Message $message): ?string => $message->getContent(),
            $messages,
        ));
        self::assertNull($messages[1]->getMetadata('stop_reason'));
        $provider->assertCallCount(2);
    }

    public function testRepeatedEscapeBeforeExecutionRetainsUserAndAdvancesWaitingTurnOnce(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Next answer.'));
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $runtime = new ConversationRuntime($agent, new ConversationView(new VirtualTerminal(), 'Neuron AI', 'Conversation'));
        $runtime->submitMessage(new MessageForAgent('First question.'));
        $runtime->submitMessage(new MessageForAgent('Next question.'));
        $runtime->requestInterruption();
        $runtime->requestInterruption();
        $runtime->tick();
        EventLoop::run();
        $runtime->tick();
        $runtime->tick();
        EventLoop::run();
        self::assertFalse($runtime->tick());
        self::assertFalse($runtime->isBusy());
        self::assertCount(3, $agent->getChatHistory()->getMessages());
        self::assertSame('interrupted', $agent->getChatHistory()->getMessages()[0]->getMetadata('stop_reason'));
        $provider->assertCallCount(1);
    }
}
