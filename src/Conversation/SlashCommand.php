<?php

declare(strict_types=1);

namespace NeuronTui\Conversation;

/**
 * A command that expects the Agent to be standing still.
 *
 * It is handed the Controls and whatever was typed after its name — the empty
 * string when nothing was — and whatever it does with them is the Host
 * Application's code, which the Conversation TUI survives.
 */
interface SlashCommand extends Command
{
    public function run(Controls $controls, string $arguments): void;
}
