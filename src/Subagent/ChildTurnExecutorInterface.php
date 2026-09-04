<?php

declare(strict_types=1);

namespace NeuronTui\Subagent;

use Amp\Cancellation;
use Amp\Future;

/** @internal */
interface ChildTurnExecutorInterface
{
    /**
     * @return Future<ChildTurnResult>
     */
    public function execute(
        ChildTurn $turn,
        Cancellation $cancellation,
    ): Future;

    /** Cancels all work and releases resources owned by the current Session. */
    public function cancel(): void;
}
