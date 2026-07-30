<?php

declare(strict_types=1);

namespace NeuronCli\Internal;

use Closure;
use NeuronAI\Tools\ToolInterface;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Tui\Widget\Util\StringUtils;

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
        $activity = new TextWidget(self::callPreview($tool));
        $activity->addStyleClass('tool');
        $height = $this->height($activity);
        $this->activityHeights[spl_object_id($activity)] = $height;
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
        $activity->setText(
            self::callPreview($tool)
            . "\n  ⎿ "
            . self::safePreview($tool->getResult()),
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
