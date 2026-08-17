<?php

declare(strict_types=1);

namespace NeuronCli\Tui;

use NeuronAI\Tools\ToolInterface;
use Symfony\Component\Tui\Widget\Util\StringUtils;

/**
 * Correlates and safely renders one group of tool calls and results.
 *
 * @internal
 */
final class ToolActivity
{
    private const int DETAIL_WIDTH = 120;

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
            self::callPreview($tool) . "\n  ⎿ Running…",
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
        $elapsed = microtime(true) - $startedAt;
        $duration = $elapsed < 1
            ? '<1s'
            : round($elapsed) . 's';
        $activity->setText(
            self::callPreview($tool)
            . "\n  ⎿ "
            . self::safePreview($tool->getResult())
            . "\n  Done in "
            . $duration,
        );
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

    private static function callPreview(ToolInterface $tool): string
    {
        $inputs = json_encode(
            $tool->getInputs(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return '● '
            . self::safePreview($tool->getName())
            . ' '
            . self::safePreview($inputs === false ? '{}' : $inputs);
    }

    private static function safePreview(string $value): string
    {
        $value = StringUtils::stripControlBytes(
            StringUtils::sanitizeUtf8($value),
        );
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';

        return mb_strimwidth(
            $value,
            0,
            self::DETAIL_WIDTH,
            '…',
            'UTF-8',
        );
    }
}
