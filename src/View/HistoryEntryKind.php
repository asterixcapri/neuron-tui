<?php

declare(strict_types=1);

namespace NeuronTui\View;

/**
 * The presentation category of a History pane entry, whether projected from
 * the Agent's History or added during interaction.
 *
 * @internal
 */
enum HistoryEntryKind
{
    case UserMessage;
    case AssistantMessage;
    case ToolActivity;
    case Notice;
    case Warning;
    case Error;
    case ResponseStopped;
    case WorkingIndicator;
}
