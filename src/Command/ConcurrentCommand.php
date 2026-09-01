<?php

declare(strict_types=1);

namespace NeuronTui\Command;

use NeuronTui\Conversation\ConcurrentControls;

/**
 * A command that can run while a Turn is under way.
 *
 * Its run is synchronous, but may overlap the Agent's work. It therefore
 * receives ConcurrentControls: only the operations whose meaning remains
 * stable while an answer is on its way.
 */
interface ConcurrentCommand
{
    /**
     * The name it answers to, slash included: `/help`.
     */
    public function name(): string;

    /**
     * One line, for a listing of what can be typed here.
     */
    public function describe(): string;

    public function run(
        ConcurrentControls $controls,
        string $arguments,
    ): void;
}
