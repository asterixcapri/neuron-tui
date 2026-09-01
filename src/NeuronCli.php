<?php

declare(strict_types=1);

namespace NeuronCli;

use NeuronAI\Agent\Agent;
use NeuronCli\Conversation\CommandKit;
use NeuronCli\Conversation\ConversationRuntime;
use NeuronCli\Conversation\RunsWhileWorking;
use NeuronCli\Conversation\SlashCommand;
use Symfony\Component\Tui\Terminal\TerminalInterface;

/**
 * Configures and starts a Conversation TUI.
 */
final class NeuronCli
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
