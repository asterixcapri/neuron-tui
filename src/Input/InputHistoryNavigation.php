<?php

declare(strict_types=1);

namespace NeuronTui\Input;

use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\InputHistory\InputHistory;

use function array_key_last;
use function max;

/** Composer recall position and draft; persisted inputs belong to InputHistory.
 * @internal
 */
final class InputHistoryNavigation
{
    private ?int $position = null;

    private ?UserMessage $draft = null;

    public function __construct(private readonly InputHistory $history) {}

    /**
     * Moves toward older inputs, entering navigation at the newest one.
     *
     * @phpstan-impure
     */
    public function older(UserMessage $draft = new UserMessage('')): ?UserMessage
    {
        $entries = $this->history->list();

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

    /**
     * Moves toward newer inputs, restoring the draft past the end.
     *
     * @phpstan-impure
     */
    public function newer(): ?UserMessage
    {
        if ($this->position === null) {
            return null;
        }

        $entries = $this->history->list();

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
        $this->draft = null;
    }

}
