<?php

declare(strict_types=1);

namespace NeuronTui\View;

use Normalizer;

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

        $name = $matches[1];
        if (!self::isSkillName($name)) {
            return $message;
        }

        $command = '/' . $name;
        $request = $matches[2] ?? '';

        return $request === '' ? $command : $command . "\n\n" . $request;
    }

    private static function isSkillName(string $name): bool
    {
        $normalized = Normalizer::normalize($name, Normalizer::FORM_KC);
        if (!is_string($normalized)) {
            return false;
        }

        return mb_strlen($name, 'UTF-8') <= 64
            && mb_strtolower($normalized, 'UTF-8') === $normalized
            && preg_match('/\A[\p{L}\p{N}]+(?:-[\p{L}\p{N}]+)*\z/u', $normalized) === 1;
    }
}
