<?php

declare(strict_types=1);

namespace NeuronCli\Tui;

use Closure;
use NeuronAI\Tools\ToolInterface;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * Correlates and safely renders one group of tool calls and results.
 *
 * @internal
 */
final class ToolActivity
{
    private const int DETAIL_WIDTH = 120;

    /** @var array<string, TextWidget> */
    private array $activitiesByCallId = [];

    /** @var array<string, list<TextWidget>> */
    private array $fallbackActivities = [];

    /** @var array<int, int> */
    private array $activityHeights = [];

    /** @var array<int, float> */
    private array $activityStartedAt = [];

    private int $activityCount = 0;

    /**
     * @param Closure(TextWidget, int): void $addToHistory
     * @param Closure(int): void $historyHeightChanged
     */
    public function __construct(
        private readonly int $contentColumns,
        private readonly Closure $addToHistory,
        private readonly Closure $historyHeightChanged,
    ) {
    }

    public function start(ToolInterface $tool): void
    {
        $activity = new TextWidget(
            self::callPreview($tool) . "\n  ⎿ Running…",
        );
        $activity->addStyleClass('tool');
        $height = $this->height($activity);
        $id = spl_object_id($activity);
        $this->activityHeights[$id] = $height;
        $this->activityStartedAt[$id] = microtime(true);
        ($this->addToHistory)($activity, $height);
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

        if (!$activity instanceof TextWidget) {
            $this->start($tool);
            $activity = $this->matchingActivity($tool);
        }

        if (!$activity instanceof TextWidget) {
            return;
        }

        $id = spl_object_id($activity);
        $previousHeight = $this->activityHeights[$id] ?? 0;
        $elapsed = microtime(true) - $this->activityStartedAt[$id];
        $duration = $elapsed < 1
            ? '<1s'
            : round($elapsed) . 's';
        $activity->setText(
            self::callPreview($tool)
            . "\n  ⎿ "
            . DisplayableText::preview(
                $tool->getResult(),
                self::DETAIL_WIDTH,
            )
            . "\n  Done in "
            . $duration,
        );
        $height = $this->height($activity);
        $this->activityHeights[$id] = $height;
        ($this->historyHeightChanged)($height - $previousHeight);
    }

    public function hasActivity(): bool
    {
        return $this->activityCount > 0;
    }

    private function matchingActivity(ToolInterface $tool): ?TextWidget
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

    private function height(TextWidget $activity): int
    {
        return count($activity->render(new RenderContext(
            max(1, $this->contentColumns),
            PHP_INT_MAX,
        )));
    }

    private static function callPreview(ToolInterface $tool): string
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
}
