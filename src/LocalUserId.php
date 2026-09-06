<?php

declare(strict_types=1);

namespace NeuronTui;

/** Resolves the local host's identity when composing a SessionStore. */
final class LocalUserId
{
    public static function resolve(?string $configured = null): string
    {
        if ($configured !== null && trim($configured) !== '') {
            return $configured;
        }

        foreach (['USER', 'USERNAME', 'LOGNAME'] as $name) {
            $value = getenv($name);
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return 'local';
    }
}
