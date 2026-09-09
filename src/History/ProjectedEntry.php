<?php

declare(strict_types=1);

namespace NeuronTui\History;

/**
 * One item of the History as a person is meant to see it.
 *
 * Ordinary TextContent is retained without projection-level sanitization.
 * Reasoning is excluded and attachments become placeholders; filenames and
 * tool previews receive their existing display preparation. Tool activity
 * may be pending or combine a call and its result from separate messages.
 * The kind identifies a presentation category for whoever paints the entry.
 *
 * Nothing here knows a widget, a style or a scroll position.
 *
 * @internal
 */
final class ProjectedEntry
{
    public function __construct(
        public readonly ProjectedEntryKind $kind,
        public readonly string $text,
    ) {
    }
}
