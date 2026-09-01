<?php

declare(strict_types=1);

namespace NeuronTui\History;

use NeuronAI\Tools\ToolInterface;
use NeuronTui\Tui\DisplayableText;

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
        return self::call($tool) . "\n  ⎿ Running…";
    }

    /**
     * A call whose result came back after that long a wait.
     */
    public static function completed(
        ToolInterface $tool,
        float $waitedSeconds,
    ): string {
        return self::call($tool)
            . "\n  ⎿ "
            . DisplayableText::preview($tool->getResult(), self::DETAIL_WIDTH)
            . "\n  Done in "
            . self::duration($waitedSeconds);
    }

    private static function call(ToolInterface $tool): string
    {
        $inputs = json_encode(
            $tool->getInputs(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return '● '
            . DisplayableText::preview($tool->getName(), self::DETAIL_WIDTH)
            . ' '
            . DisplayableText::preview(
                $inputs === false ? '{}' : $inputs,
                self::DETAIL_WIDTH,
            );
    }

    private static function duration(float $seconds): string
    {
        return $seconds < 1 ? '<1s' : round($seconds) . 's';
    }
}
