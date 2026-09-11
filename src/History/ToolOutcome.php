<?php

declare(strict_types=1);

namespace NeuronTui\History;

use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;
use Throwable;

/** Protocol results for work that could not return an ordinary outcome. @internal */
final class ToolOutcome
{
    public static function notExecuted(ToolInterface $tool): Tool
    {
        return self::result($tool, [
            'neuron_tui' => 'tool_outcome',
            'status' => 'not_executed',
            'reason' => 'turn_interrupted',
            'message' => 'Execution never began because the person interrupted the Turn.',
        ]);
    }

    public static function failed(ToolInterface $tool, Throwable $failure): Tool
    {
        return self::result($tool, [
            'neuron_tui' => 'tool_outcome',
            'status' => 'failed',
            'error_type' => $failure::class,
            'message' => $failure->getMessage(),
        ]);
    }

    public static function status(ToolInterface $tool): ?string
    {
        $body = json_decode($tool->getResult(), true);

        return is_array($body)
            && ($body['neuron_tui'] ?? null) === 'tool_outcome'
            && is_string($body['status'] ?? null)
            ? $body['status'] : null;
    }

    public static function text(ToolInterface $tool): string
    {
        if (self::status($tool) !== 'failed') {
            return $tool->getResult();
        }

        $body = json_decode($tool->getResult(), true);

        return is_array($body) && is_string($body['message'] ?? null)
            ? $body['message'] : $tool->getResult();
    }

    /** @param array<string, string> $body */
    private static function result(ToolInterface $tool, array $body): Tool
    {
        return (new Tool($tool->getName(), $tool->getDescription()))
            ->setCallId($tool->getCallId())
            ->setInputs($tool->getInputs())
            ->setResult(json_encode($body, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
