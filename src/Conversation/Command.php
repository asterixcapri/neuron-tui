<?php

declare(strict_types=1);

namespace NeuronTui\Conversation;

/**
 * Whoever answers to a name typed after a slash.
 *
 * A command says what it is called and describes itself in one line; what it
 * then does is written by the Host Application that mounted it.
 */
interface Command
{
    /**
     * The name it answers to, slash included: `/review`.
     */
    public function name(): string;

    /**
     * One line, for a listing of what can be typed here.
     */
    public function describe(): string;
}
