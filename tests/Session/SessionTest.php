<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Session;

use DateTimeImmutable;
use InvalidArgumentException;
use NeuronTui\Session\Session;
use PHPUnit\Framework\TestCase;

final class SessionTest extends TestCase
{
    public function testStorageSizeCannotBeNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'A Session storage size cannot be negative.',
        );

        new Session(
            'session-key',
            new DateTimeImmutable(),
            'The opening words',
            -1,
        );
    }
}
