<?php

declare(strict_types=1);

namespace NeuronCli\Conversation;

/**
 * The Slash commands the Conversation TUI carries out itself.
 *
 * There are exactly three of them and no way for a Host Application to add a
 * fourth: a fixed set does not justify a registry.
 *
 * @internal
 */
enum SlashCommand: string
{
    case Clear = '/clear';

    case Sessions = '/sessions';

    case Exit = '/exit';
}
