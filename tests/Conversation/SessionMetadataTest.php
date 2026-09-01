<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Conversation;

use DateTimeImmutable;
use Generator;
use NeuronTui\Conversation\SessionMetadata;
use NeuronTui\Session\Session;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SessionMetadataTest extends TestCase
{
    #[DataProvider('relativeAges')]
    public function testItPresentsTheRelativeAgeOfASession(
        string $lastUsedAt,
        string $expected,
    ): void {
        $session = new Session(
            'session-key',
            new DateTimeImmutable($lastUsedAt),
            'The opening words',
        );

        self::assertSame(
            $expected,
            SessionMetadata::format(
                $session,
                new DateTimeImmutable('2026-09-01 12:00:00 UTC'),
            ),
        );
    }

    /**
     * @return Generator<string, array{string, string}>
     */
    public static function relativeAges(): Generator
    {
        yield 'present' => ['2026-09-01 12:00:00 UTC', 'just now'];
        yield 'future' => ['2026-09-01 12:00:01 UTC', 'just now'];
        yield 'one second' => ['2026-09-01 11:59:59 UTC', '1 second ago'];
        yield 'seconds' => ['2026-09-01 11:59:40 UTC', '20 seconds ago'];
        yield 'one minute' => ['2026-09-01 11:59:00 UTC', '1 minute ago'];
        yield 'minutes' => ['2026-09-01 11:58:00 UTC', '2 minutes ago'];
        yield 'one hour' => ['2026-09-01 11:00:00 UTC', '1 hour ago'];
        yield 'hours' => ['2026-09-01 10:00:00 UTC', '2 hours ago'];
        yield 'one day' => ['2026-08-31 12:00:00 UTC', '1 day ago'];
        yield 'days' => ['2026-08-30 12:00:00 UTC', '2 days ago'];
        yield 'one month' => ['2026-08-02 12:00:00 UTC', '1 month ago'];
        yield 'months' => ['2026-07-03 12:00:00 UTC', '2 months ago'];
        yield 'one year' => ['2025-09-01 12:00:00 UTC', '1 year ago'];
        yield 'years' => ['2024-09-01 12:00:00 UTC', '2 years ago'];
    }
}
