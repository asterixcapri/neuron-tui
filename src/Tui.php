<?php

declare(strict_types=1);

namespace NeuronTui;

use NeuronAI\Agent\Agent;
use NeuronTui\Conversation\CommandKit;
use NeuronTui\Conversation\ConversationRuntime;
use NeuronTui\Conversation\RunsWhileWorking;
use NeuronTui\Conversation\SlashCommand;
use Symfony\Component\Tui\Terminal\TerminalInterface;

/**
 * Configures and starts a Conversation TUI.
 */
final class Tui
{
    private readonly ConversationRuntime $runtime;

    /**
     * @param list<SlashCommand|RunsWhileWorking|CommandKit> $commands
     *     everything that can be typed here after a slash: the Conversation
     *     TUI mounts nothing on its own, and a kit stands for the commands it
     *     carries
     */
    public function __construct(
        Agent $agent,
        string $title = 'Neuron AI',
        string $subtitle = 'Agent conversation',
        ?TerminalInterface $terminal = null,
        array $commands = [],
    ) {
        $this->runtime = new ConversationRuntime(
            $agent,
            $title,
            $subtitle,
            $terminal,
            $commands,
        );
    }

    public function run(): void
    {
        $this->runtime->run();
    }
}
