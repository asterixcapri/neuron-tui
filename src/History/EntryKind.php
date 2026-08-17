<?php

declare(strict_types=1);

namespace NeuronCli\History;

/**
 * What an entry of the projected History reports.
 *
 * @internal
 */
enum EntryKind
{
    /** Something the person wrote to the Agent. */
    case Person;

    /** Something the Agent said. */
    case Agent;

    /** A tool the Agent called, with its result if one came back. */
    case Tool;
}
