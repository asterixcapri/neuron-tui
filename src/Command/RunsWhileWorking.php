<?php

declare(strict_types=1);

namespace NeuronTui\Command;

use NeuronTui\Conversation\LimitedControls;

/**
 * A command that runs whether or not the Agent is standing still.
 *
 * Answering it mid-turn is what this says, and the Conversation TUI reads it
 * from the type rather than from a list of names it keeps: everything else is
 * turned away while an answer is on its way, because a command that changed
 * the conversation then would have that answer land where it does not belong.
 *
 * What comes with the permission is fewer Controls. There is no Picker here —
 * nobody should be choosing from a list while answers and tool calls scroll
 * underneath — and no Agent, which is busy: saying, warning, listing the
 * commands and leaving are what is left. The narrowing is in the type, so a
 * command of this kind cannot even write the opening of a list.
 */
interface RunsWhileWorking extends Command
{
    public function run(LimitedControls $controls, string $arguments): void;
}
