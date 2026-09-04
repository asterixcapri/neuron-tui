<?php

declare(strict_types=1);

namespace NeuronTui\Conversation;

use NeuronInteraction\InputHistory\InputHistory;

/** @internal Each composer owns its recall position and draft. */
final class InputHistoryNavigation
{
    private ?int $position = null;

    private string $draft = '';

    public function __construct(private readonly InputHistory $history)
    {
    }

    /** Moves toward older inputs, entering navigation at the newest one. */
    public function older(string $draft = ''): ?string
    {
        $entries = $this->history->entries();

        if ($entries === []) {
            return null;
        }

        if ($this->position === null) {
            $this->draft = $draft;
            $this->position = array_key_last($entries);
        } else {
            $this->position = max(0, $this->position - 1);
        }

        return $entries[$this->position];
    }

    /** Moves toward newer inputs, restoring the draft past the end. */
    public function newer(): ?string
    {
        if ($this->position === null) {
            return null;
        }

        $entries = $this->history->entries();

        if ($this->position === array_key_last($entries)) {
            $draft = $this->draft;
            $this->leave();

            return $draft;
        }

        ++$this->position;

        return $entries[$this->position];
    }

    public function isNavigating(): bool
    {
        return $this->position !== null;
    }

    /** The composer draft has become independent from its stored source. */
    public function leave(): void
    {
        $this->position = null;
        $this->draft = '';
    }
}
