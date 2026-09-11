<?php

declare(strict_types=1);

namespace NeuronTui\History;

use NeuronAI\Tools\ToolInterface;

/**
 * Where each tool call was shown, so that its result can find it again.
 *
 * Unique call ids match whichever order the results come back in. Providers
 * can repeat ids or omit them: match their name and inputs before falling
 * back to occurrence order for otherwise indistinguishable calls. A
 * result that answers nothing at all is reported as unmatched rather than
 * guessed at.
 *
 * @internal
 */
final class ToolCallCorrelation
{
    /** @var array<string, list<int>> */
    private array $positionsByCallId = [];

    /** @var array<string, list<int>> */
    private array $positionsByName = [];

    /** @var array<int, ToolInterface> */
    private array $calls = [];

    public function registerCall(ToolInterface $tool, int $position): void
    {
        $this->calls[$position] = $tool;
        $callId = $tool->getCallId();

        if ($callId === null) {
            $this->positionsByName[$tool->getName()][] = $position;

            return;
        }

        $this->positionsByCallId[$callId][] = $position;
    }

    /**
     * Where the call this result answers was shown, if it was shown at all.
     * Matching consumes one waiting occurrence for the call ID, or the tool
     * name without an ID. Inputs distinguish repeated calls whose parallel
     * results arrive out of order. An unmatched result returns null.
     */
    public function matchResult(ToolInterface $tool): ?int
    {
        $callId = $tool->getCallId();

        if ($callId !== null) {
            if (($this->positionsByCallId[$callId] ?? []) === []) {
                return null;
            }

            return $this->takePosition($tool, $this->positionsByCallId[$callId]);
        }

        $name = $tool->getName();

        if (($this->positionsByName[$name] ?? []) === []) {
            return null;
        }

        return $this->takePosition($tool, $this->positionsByName[$name]);
    }

    /**
     * @param non-empty-list<int> $positions
     * @param-out list<int> $positions
     */
    private function takePosition(ToolInterface $tool, array &$positions): int
    {
        foreach ($positions as $index => $position) {
            $call = $this->calls[$position];

            if ($call->getName() === $tool->getName() && $call->getInputs() === $tool->getInputs()) {
                array_splice($positions, $index, 1);
                unset($this->calls[$position]);

                return $position;
            }
        }

        $position = array_shift($positions);
        unset($this->calls[$position]);

        return $position;
    }
}
