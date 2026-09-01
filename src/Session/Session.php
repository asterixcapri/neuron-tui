<?php

declare(strict_types=1);

namespace NeuronTui\Session;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * What a person needs to recognize a Session without opening it.
 *
 * A key addresses the Session and means nothing to a person; the time it was
 * last used and the opening of the conversation are what tell one Session
 * from another on screen. The title is the first thing the person wrote in
 * it, as text and nothing else — a provider never hands back a widget, a
 * style or a stored payload. A Session nobody has written to yet has no
 * title, so it has none to hand back either. A provider may also report the
 * non-negative number of bytes occupied by the Session where it persists it.
 */
final readonly class Session
{
    public function __construct(
        public string $key,
        public DateTimeImmutable $lastUsedAt,
        public string $title,
        public ?int $storageSize = null,
    ) {
        if ($this->storageSize !== null && $this->storageSize < 0) {
            throw new InvalidArgumentException(
                'A Session storage size cannot be negative.',
            );
        }
    }
}
