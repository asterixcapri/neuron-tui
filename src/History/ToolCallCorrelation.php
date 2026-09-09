<?php

declare(strict_types=1);

namespace NeuronTui\History;

use NeuronAI\Tools\ToolInterface;

/**
 * Where each tool call was shown, so that its result can find it again.
 *
 * A call id identifies a call exactly, whichever order the results come back
 * in. A provider that mints no call id leaves only the tool's name to go on,
 * so calls of that name are answered in the order they were made — and a
 * result that answers nothing at all is reported as unmatched rather than
 * guessed at.
 *
 * @internal
 */
final class ToolCallCorrelation
{
    /** @var array<string, int> */
    private array $positionByCallId = [];

    /** @var array<string, list<int>> */
    private array $positionsByName = [];

    public function registerCall(ToolInterface $tool, int $position): void
    {
        $callId = $tool->getCallId();

        if ($callId === null) {
            $this->positionsByName[$tool->getName()][] = $position;

            return;
        }

        $this->positionByCallId[$callId] = $position;
    }

    /**
     * Where the call this result answers was shown, if it was shown at all.
     * An explicit call ID returns its stored position without consuming the
     * association. Without a call ID, matching consumes the first waiting
     * position for the tool's name. An unmatched result returns null.
     */
    public function matchResult(ToolInterface $tool): ?int
    {
        $callId = $tool->getCallId();

        if ($callId !== null) {
            return $this->positionByCallId[$callId] ?? null;
        }

        $name = $tool->getName();

        if (($this->positionsByName[$name] ?? []) === []) {
            return null;
        }

        return array_shift($this->positionsByName[$name]);
    }
}
