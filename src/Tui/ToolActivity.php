<?php

declare(strict_types=1);

namespace NeuronTui\Tui;

use NeuronAI\Tools\ToolInterface;
use NeuronTui\History\ToolActivityText;
use NeuronTui\History\ToolCorrelation;

/**
 * Paints one group of tool calls and results as they happen.
 *
 * Neither of the two rules a person can see is decided here: which call a
 * result answers, and what a person is told about it, both come from the
 * History, so live activity and activity read back out of a stored Session
 * read the same. What is left is the painting.
 *
 * @internal
 */
final class ToolActivity
{
    private readonly ToolCorrelation $correlation;

    /** @var list<HistoryEntry> */
    private array $activities = [];

    /** @var array<int, float> */
    private array $calledAt = [];

    public function __construct(private readonly HistoryPane $pane)
    {
        $this->correlation = new ToolCorrelation();
    }

    /**
     * Shows a call, and reports where it was shown.
     */
    public function start(ToolInterface $tool): int
    {
        $this->activities[] = $this->pane->addNote(
            ToolActivityText::pending($tool),
            'tool',
        );
        $position = count($this->activities) - 1;
        $this->calledAt[$position] = microtime(true);
        $this->correlation->called($tool, $position);

        return $position;
    }

    public function finish(ToolInterface $tool): void
    {
        // A result nothing asked for is still worth showing, so it opens the
        // call it should have answered and closes it at once.
        $position = $this->correlation->calledAt($tool) ?? $this->start($tool);

        $this->activities[$position]->setText(ToolActivityText::completed(
            $tool,
            microtime(true) - ($this->calledAt[$position] ?? microtime(true)),
        ));
    }

    public function hasActivity(): bool
    {
        return $this->activities !== [];
    }
}
