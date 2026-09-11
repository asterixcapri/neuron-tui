<?php

declare(strict_types=1);

namespace NeuronTui\View;

/**
 * Projects Agent-facing user text into the form a person is meant to see.
 *
 * Skill invocations carry their instructions in the user message so the
 * Agent receives deterministic context. The envelope is presentation-only:
 * an exact invocation is shown as its slash command and optional request,
 * while every other message is returned unchanged.
 *
 * @internal
 */
final class UserMessageProjection
{
    private const string SKILL_INVOCATION = <<<'REGEX'
        \A<skill name="([^"<>\r\n]+)" location="[^"<>\r\n]+">\n.+?\n</skill>(?:\n\n(.+))?\z
        REGEX;

    public static function project(string $message): string
    {
        $matches = [];

        if (preg_match('~' . self::SKILL_INVOCATION . '~sD', $message, $matches) !== 1) {
            return $message;
        }

        $command = '/' . $matches[1];
        $request = $matches[2] ?? '';

        return $request === '' ? $command : $command . "\n\n" . $request;
    }
}
