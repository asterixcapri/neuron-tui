<?php

declare(strict_types=1);

namespace NeuronTui\History;

use NeuronAI\Tools\ToolInterface;
use NeuronTui\View\DisplayableText;

/**
 * How a tool call and its result are told to a person.
 *
 * Live tool activity and tool activity read back out of a stored Session are
 * the same thing to whoever is reading, so the rule that decides what a
 * person is told about a call — and how much of an unchecked result reaches
 * the screen — lives here once rather than once per path.
 *
 * @internal
 */
final class ToolActivityText
{
    private const int DETAIL_WIDTH = 120;

    /**
     * A call whose result has not come back.
     */
    public static function pending(ToolInterface $tool): string
    {
        return self::callText($tool) . "\n  ⎿ Running…";
    }

    /**
     * A completed call with elapsed seconds, or a presentation fallback when
     * historical timing is unavailable.
     */
    public static function completed(
        ToolInterface $tool,
        float $elapsedSeconds,
    ): string {
        $status = ToolOutcome::status($tool);

        if ($status === 'not_executed') {
            return self::callText($tool) . "\n  ⎿ Not executed · Turn interrupted";
        }

        return self::callText($tool)
            . "\n  ⎿ "
            . DisplayableText::preview(ToolOutcome::text($tool), self::DETAIL_WIDTH)
            . ($status === 'failed' ? "\n  Failed in " : "\n  Done in ")
            . self::duration($elapsedSeconds);
    }

    private static function callText(ToolInterface $tool): string
    {
        $encodedInputs = json_encode(
            $tool->getInputs(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return '● '
            . DisplayableText::preview($tool->getName(), self::DETAIL_WIDTH)
            . ' '
            . DisplayableText::preview(
                $encodedInputs === false ? '{}' : $encodedInputs,
                self::DETAIL_WIDTH,
            );
    }

    private static function duration(float $seconds): string
    {
        return $seconds < 1 ? '<1s' : round($seconds) . 's';
    }
}
