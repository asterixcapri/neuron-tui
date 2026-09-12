<?php

declare(strict_types=1);

namespace NeuronTui\Http;

use function Amp\delay;

/** One cooperative HTTP stop request, consumed at most once. @internal */
final class StopRequest
{
    private bool $requested = false;
    private bool $applied = false;
    private bool $finished = false;

    private float $lastYield = 0.0;

    public function request(): bool
    {
        if ($this->requested || $this->finished) {
            return false;
        }

        $this->requested = true;

        return true;
    }

    /** @phpstan-impure Yields so buffered terminal input can request a stop. */
    public function checkpoint(): bool
    {
        // Some providers poll EOF for each byte; yield at most every 10 ms.
        if (microtime(true) - $this->lastYield >= 0.01) {
            delay(0);
            $this->lastYield = microtime(true);
        }

        return $this->requested && !$this->applied && !$this->finished;
    }

    public function apply(): void
    {
        $this->applied = true;
    }

    public function finish(): bool
    {
        $this->finished = true;

        return $this->applied;
    }
}
