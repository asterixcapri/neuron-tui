<?php

declare(strict_types=1);

namespace NeuronTui\Conversation;

/**
 * Reads what a person typed and says what it is.
 *
 * Everything a person submits is one of two things: a Slash command, name and
 * arguments apart, or a message for the Agent. Deciding which is this module's
 * whole responsibility, so nothing downstream inspects raw input again.
 *
 * Which commands exist is not asked here: a name that no command answers to
 * is read the same way as one that does.
 *
 * @internal
 */
final class Submission
{
    /**
     * What separates the name of a command from its arguments, and what is
     * dropped from around them. One list serves both, so that a character
     * ending the name cannot then be kept as the first of the arguments.
     */
    private const string WHITESPACE = " \t\n\r\v\f\0";

    /**
     * Anything beginning with a slash is a command: the name is its first
     * word, the arguments are the rest with the whitespace around them
     * dropped, so `/exit now` is `/exit` with `now` and `/exit ` is `/exit`
     * with nothing. A message keeps every character the person typed, leading
     * slash and spacing included.
     */
    public static function interpret(
        string $input,
    ): SlashCommandInput|MessageForAgent {
        if (!str_starts_with($input, '/')) {
            return new MessageForAgent($input);
        }

        // The input begins with a slash, so there is always a first word:
        // it ends at the first whitespace, or at the end of the input.
        $endOfName = strcspn($input, self::WHITESPACE);

        return new SlashCommandInput(
            substr($input, 0, $endOfName),
            trim(substr($input, $endOfName), self::WHITESPACE),
        );
    }
}
