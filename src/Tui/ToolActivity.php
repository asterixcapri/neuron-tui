<?php

declare(strict_types=1);

namespace NeuronCli\Tui;

use NeuronAI\Tools\ToolInterface;
use NeuronCli\History\ToolActivityText;

/**
 * Correlates and paints one group of tool calls and results as they happen.
 *
 * What a person is told about a call is not decided here: the text comes from
 * the History projection, so live activity and activity read back out of a
 * stored Session read the same.
 *
 * @internal
 */
final class ToolActivity
{
    /** @var array<string, HistoryEntry> */
    private array $activitiesByCallId = [];

    /** @var array<string, list<HistoryEntry>> */
    private array $fallbackActivities = [];

    /** @var array<int, float> */
    private array $activityStartedAt = [];

    private int $activityCount = 0;

    public function __construct(private readonly HistoryPane $pane)
    {
    }

    public function start(ToolInterface $tool): void
    {
        $activity = $this->pane->addNote(
            ToolActivityText::pending($tool),
            'tool',
        );
        $this->activityStartedAt[spl_object_id($activity)] = microtime(true);
        $this->activityCount++;

        $callId = $tool->getCallId();

        if ($callId === null) {
            $this->fallbackActivities[$tool->getName()][] = $activity;
        } else {
            $this->activitiesByCallId[$callId] = $activity;
        }
    }

    public function finish(ToolInterface $tool): void
    {
        $activity = $this->matchingActivity($tool);

        if (!$activity instanceof HistoryEntry) {
            $this->start($tool);
            $activity = $this->matchingActivity($tool);
        }

        if (!$activity instanceof HistoryEntry) {
            return;
        }

        $id = spl_object_id($activity);
        $startedAt = $this->activityStartedAt[$id] ?? microtime(true);
        unset($this->activityStartedAt[$id]);

        $activity->setText(ToolActivityText::completed(
            $tool,
            microtime(true) - $startedAt,
        ));
    }

    public function hasActivity(): bool
    {
        return $this->activityCount > 0;
    }

    private function matchingActivity(ToolInterface $tool): ?HistoryEntry
    {
        $callId = $tool->getCallId();

        if ($callId !== null) {
            return $this->activitiesByCallId[$callId] ?? null;
        }

        $name = $tool->getName();

        if (($this->fallbackActivities[$name] ?? []) === []) {
            return null;
        }

        return array_shift($this->fallbackActivities[$name]);
    }
}
