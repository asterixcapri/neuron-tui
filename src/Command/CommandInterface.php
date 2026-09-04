<?php

declare(strict_types=1);

namespace NeuronTui\Command;

use NeuronTui\Conversation\Controls;

/**
 * A command that receives the full Controls of a settled conversation.
 *
 * A command says what it is called and describes itself in one line; what it
 * then does is written by the Host Application that mounted it. It is handed
 * the Controls and whatever was typed after its name — empty text when
 * nothing was — and whatever it does with them is survived by the
 * Conversation TUI. It is refused while a Turn is under way, when those
 * Controls do not all have a stable meaning.
 */
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

    public function run(Controls $controls, CommandArguments $arguments): void;
}
