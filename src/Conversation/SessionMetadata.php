<?php

declare(strict_types=1);

namespace NeuronTui\Conversation;

use DateTimeImmutable;
use NeuronTui\Session\Session;

/**
 * Presents the recognition metadata shown beneath a Session title.
 *
 * @internal
 */
final readonly class SessionMetadata
{
    public static function format(
        Session $session,
        DateTimeImmutable $now,
    ): string {
        $relativeAge = self::relativeAge($session, $now);

        if ($session->storageSize === null) {
            return $relativeAge;
        }

        return $relativeAge . ' · ' . self::storageSize($session->storageSize);
    }

    private static function relativeAge(
        Session $session,
        DateTimeImmutable $now,
    ): string {
        $age = $now->getTimestamp() - $session->lastUsedAt->getTimestamp();

        if ($age <= 0) {
            return 'just now';
        }

        [$amount, $unit] = match (true) {
            $age < 60 => [$age, 'second'],
            $age < 60 * 60 => [intdiv($age, 60), 'minute'],
            $age < 24 * 60 * 60 => [intdiv($age, 60 * 60), 'hour'],
            $age < 30 * 24 * 60 * 60 => [intdiv($age, 24 * 60 * 60), 'day'],
            $age < 365 * 24 * 60 * 60 => [
                intdiv($age, 30 * 24 * 60 * 60),
                'month',
            ],
            default => [intdiv($age, 365 * 24 * 60 * 60), 'year'],
        };

        return $amount . ' ' . $unit . ($amount === 1 ? '' : 's') . ' ago';
    }

    private static function storageSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB'];
        $size = (float) $bytes;
        $unit = 0;

        while ($size >= 1_024 && $unit < count($units) - 1) {
            $size /= 1_024;
            ++$unit;
        }

        $amount = rtrim(
            rtrim(number_format($size, 1, '.', ''), '0'),
            '.',
        );

        return $amount . $units[$unit];
    }
}
