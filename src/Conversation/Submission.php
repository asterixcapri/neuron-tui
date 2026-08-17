<?php

declare(strict_types=1);

namespace NeuronCli\Conversation;

/**
 * Reads what a person typed and says what it is.
 *
 * Everything a person submits is one of three things: a Slash command the TUI
 * carries out, something in the reserved Slash namespace that no command
 * answers to, or a message for the Agent. Deciding which is this module's
 * whole responsibility, so nothing downstream inspects raw input again.
 *
 * @internal
 */
final class Submission
{
    /**
     * A command is the whole of the input, give or take the whitespace around
     * it: `/exit now` is not `/exit`, while `/exit ` is. A message keeps every
     * character the person typed, leading slash and spacing included.
     */
    public static function interpret(
        string $input,
    ): SlashCommand|UnknownSlashCommand|MessageForAgent {
        if (!str_starts_with($input, '/')) {
            return new MessageForAgent($input);
        }

        $command = SlashCommand::tryFrom(trim($input));

        if ($command instanceof SlashCommand) {
            return $command;
        }

        // The input begins with a slash, so there is always a first word.
        return new UnknownSlashCommand((string) strtok($input, " \t\n"));
    }
}
