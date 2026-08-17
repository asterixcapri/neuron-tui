<?php

declare(strict_types=1);

namespace NeuronCli\Tui;

use Closure;

/**
 * The animation that tells a person the Agent is still busy.
 *
 * One module owns all of it: the frames, the elapsed counter, how often the
 * line is allowed to be redrawn, and the line itself in the History pane.
 *
 * The current time is a parameter of every operation that needs one. The
 * module never reads the clock, so the counter and the throttle can both be
 * exercised without sleeping.
 *
 * @internal
 */
final class WorkingIndicator
{
    private const array FRAMES = ['✶', '✸', '✹', '✺', '✹', '✷'];

    private const float REDRAW_SECONDS = 0.08;

    private int $frame = 0;

    private float $startedAt = 0.0;

    private float $redrawnAt = 0.0;

    private ?HistoryEntry $line = null;

    public function __construct(private readonly HistoryPane $history) {}

    /**
     * Shows the indicator, counting from the moment given.
     */
    public function start(float $now): void
    {
        $this->frame = 0;
        $this->startedAt = $now;
        $this->redrawnAt = $now;
        $this->show($now);
    }

    /**
     * Moves to the next frame, unless the last redraw is too recent for the
     * change to be worth painting.
     */
    public function advance(float $now): void
    {
        if (!$this->line instanceof HistoryEntry) {
            return;
        }

        if ($now - $this->redrawnAt < self::REDRAW_SECONDS) {
            return;
        }

        $this->frame = ($this->frame + 1) % count(self::FRAMES);
        $this->redrawnAt = $now;
        $this->line->setText($this->text($now));
    }

    /**
     * Paints something else into the History with the indicator out of the
     * way, and puts it back underneath afterwards.
     *
     * The indicator is always the last thing in the History, so anything
     * added while it is showing would otherwise appear above it. Callers say
     * what they want painted rather than remembering to bracket it.
     *
     * @param Closure(): void $paint
     */
    public function whilePaused(float $now, Closure $paint): void
    {
        if (!$this->line instanceof HistoryEntry) {
            $paint();

            return;
        }

        $this->hide();
        $paint();
        $this->show($now);
    }

    /**
     * Takes the indicator off screen. The Agent is no longer busy.
     */
    public function stop(): void
    {
        $this->hide();
    }

    private function show(float $now): void
    {
        $this->line = $this->history->addNote($this->text($now), 'loading');
        $this->history->followLatest();
    }

    private function hide(): void
    {
        if (!$this->line instanceof HistoryEntry) {
            return;
        }

        $this->history->remove($this->line);
        $this->line = null;
    }

    private function text(float $now): string
    {
        return self::FRAMES[$this->frame]
            . ' Working ('
            . (int) floor($now - $this->startedAt)
            . 's)';
    }
}
