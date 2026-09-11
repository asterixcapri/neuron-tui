<?php

declare(strict_types=1);

namespace NeuronTui\Conversation;

use function Amp\delay;

/** One idempotent interruption request belonging to one active Turn. @internal */
final class TurnInterruption
{
    private bool $requested = false;

    private bool $finished = false;

    public function request(): bool
    {
        if ($this->requested || $this->finished) {
            return false;
        }

        $this->requested = true;

        return true;
    }

    public function isRequested(): bool
    {
        return $this->requested;
    }

    /**
     * Let buffered input run at a boundary before permitting more work.
     *
     * @phpstan-impure Yields to the event loop, which may accept the request.
     */
    public function checkpoint(): bool
    {
        delay(0);

        return $this->requested;
    }

    /** Claim the single terminal outcome before any further input can act. */
    public function finish(): bool
    {
        $this->finished = true;

        return $this->requested;
    }
}
