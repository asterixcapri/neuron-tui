<?php

declare(strict_types=1);

namespace NeuronCli\Tui;

use Symfony\Component\Tui\Widget\Util\StringUtils;

/**
 * Makes text Neuron CLI does not control safe to put on a terminal.
 *
 * An Agent's answer, a tool's arguments and result, a queued message and a
 * file name all arrive as bytes nobody validated. Displaying them verbatim
 * lets a terminal-escape sequence redraw the screen; displaying them raw lets
 * invalid UTF-8 corrupt the output. Both rules live here, once.
 *
 * @internal
 */
final class DisplayableText
{
    /**
     * The text, safe to display, with its own line structure preserved.
     */
    public static function safe(string $text): string
    {
        return StringUtils::stripControlBytes(
            StringUtils::sanitizeUtf8($text),
        );
    }

    /**
     * The text, safe to display and normalized onto one visual line.
     */
    public static function singleLine(string $text): string
    {
        return preg_replace('/\s+/u', ' ', trim(self::safe($text))) ?? '';
    }

    /**
     * The text as one safe line, no wider than the given display width.
     */
    public static function preview(string $text, int $width): string
    {
        return mb_strimwidth(
            self::singleLine($text),
            0,
            $width,
            '…',
            'UTF-8',
        );
    }
}
