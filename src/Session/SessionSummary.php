<?php

declare(strict_types=1);

namespace NeuronCli\Session;

use DateTimeImmutable;

/**
 * What a person needs to recognize a Session without opening it.
 *
 * A key addresses the Session and means nothing to a person; the time it was
 * last used and the opening of the conversation are what tell one Session
 * from another on screen. The title is the first thing the person wrote in
 * it, as text and nothing else — a store never hands back a widget, a style
 * or a stored payload.
 */
final readonly class SessionSummary
{
    public function __construct(
        public string $key,
        public DateTimeImmutable $lastUsedAt,
        public string $title,
    ) {
    }
}
