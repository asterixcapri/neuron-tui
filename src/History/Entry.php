<?php

declare(strict_types=1);

namespace NeuronCli\History;

/**
 * One item of the History as a person is meant to see it.
 *
 * The text is what is left once the History's rules have run: nothing that
 * should stay hidden, no raw payload, no unchecked bytes. Tool activity is a
 * finished block of it, because a call and its result read as one thing;
 * a spoken message is the words alone, and its kind tells whoever paints it
 * who to attribute them to.
 *
 * Nothing here knows a widget, a style or a scroll position.
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
