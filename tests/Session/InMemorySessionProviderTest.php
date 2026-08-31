<?php

declare(strict_types=1);

namespace NeuronCli\Tests\Session;

use InvalidArgumentException;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronCli\Session\InMemorySessionProvider;
use PHPUnit\Framework\TestCase;

final class InMemorySessionProviderTest extends TestCase
{
    public function testAStartedSessionIsNotListedUntilItIsWrittenIn(): void
    {
        $provider = new InMemorySessionProvider();
        $provider->start();

        self::assertSame([], $provider->list());
    }

    public function testAWrittenSessionCanBeListedAndResumed(): void
    {
        $provider = new InMemorySessionProvider();
        $started = $provider->start();

        $started->addMessage(new UserMessage('The subject'));
        $listed = $provider->list();

        self::assertCount(1, $listed);
        self::assertSame('The subject', $listed[0]->title);
        self::assertSame($started, $provider->resume($listed[0]->key));
    }

    public function testAnUnknownSessionCannotBeResumed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'No Session of this Agent is named by that key.',
        );

        (new InMemorySessionProvider())->resume('unknown');
    }
}
