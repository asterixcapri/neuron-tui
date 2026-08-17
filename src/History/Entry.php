<?php

declare(strict_types=1);

namespace NeuronCli\History;

/**
 * One item of the History as a person is meant to see it.
 *
 * An entry carries only what has already been decided: nothing hidden, no raw
 * payload, no unchecked bytes. Whoever paints it chooses the glyphs and the
 * styles; the entry itself knows no terminal.
 *
 * @internal
 */
final class Entry
{
    public function __construct(
        public readonly EntryKind $kind,
        public readonly string $text,
    ) {
    }
}
