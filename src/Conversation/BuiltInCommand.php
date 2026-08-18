<?php

declare(strict_types=1);

namespace NeuronCli\Conversation;

/**
 * The Slash commands the Conversation TUI carries out itself.
 *
 * These three came before a Host Application could mount any of its own, and
 * they behave as they always did: they are looked up alongside the mounted
 * ones, and a mounted command may not take one of their names.
 *
 * @internal
 */
enum BuiltInCommand: string
{
    case Clear = '/clear';

    case Sessions = '/sessions';

    case Exit = '/exit';
}
