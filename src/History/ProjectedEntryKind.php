<?php

declare(strict_types=1);

namespace NeuronTui\History;

/**
 * The presentation category of a ProjectedEntry, independent of message roles.
 *
 * @internal
 */
enum ProjectedEntryKind
{
    /** Something the person wrote to the Agent. */
    case Person;

    /** Something the Agent said. */
    case Agent;

    /** A tool the Agent called, with its result if one came back. */
    case Tool;

    /** A Turn outcome shown separately from the Agent's words. */
    case Notice;
}
