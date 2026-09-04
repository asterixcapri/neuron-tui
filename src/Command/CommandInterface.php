<?php

declare(strict_types=1);

namespace NeuronTui\Command;

/** A named operation whose effects use the active Adapter's Command controls. */
interface CommandInterface
{
    /**
     * The name it answers to, slash omitted: `review`.
     */
    public function name(): string;

    /**
     * One line, for a listing of what can be typed here.
     */
    public function describe(): string;

    public function run(CommandControls $controls, CommandArguments $arguments): void;
}
