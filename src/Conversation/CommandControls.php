<?php

declare(strict_types=1);

namespace NeuronTui\Conversation;

use Closure;
use NeuronAI\Agent\Agent;
use NeuronInteraction\Command\CommandControlsInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\SelectionRequest;
use NeuronInteraction\Session\Sessions;
use NeuronTui\Tui\ConversationView;

/**
 * The terminal Adapter's implementation of Command controls.
 *
 * A command is code the Conversation TUI did not write, so it is given verbs
 * rather than the terminal: the widgets behind them stay out of reach and
 * remain free to change. Changing what an Agent is made of has no verb here,
 * because the Agent belongs to the Host Application and Neuron AI already
 * says how it is changed; only which Agent answers does, because that one is
 * the Conversation TUI's own to remember.
 *
 * @internal Commands depend on CommandControlsInterface rather than this Adapter.
 */
final readonly class CommandControls implements CommandControlsInterface
{
    /**
     * @param Closure(): Agent      $answering  the Agent answering right now
     * @param Closure(string): void $putToAgent how a prompt reaches the Agent
     * @param Closure(Agent): void  $answerFrom how another Agent takes over
     * @param Closure(SelectionRequest): void $select how a later selection reaches the Adapter
     * @param Closure(): void $stop how the Adapter ends the interaction
     * @param Sessions $sessions the Sessions used by this Conversation Runtime
     *
     * @internal the Conversation TUI builds these, a command receives them
     */
    public function __construct(
        private ConversationView $view,
        private Closure $answering,
        private Closure $putToAgent,
        private Closure $answerFrom,
        private Commands $mounted,
        private Sessions $sessions,
        private Closure $select,
        private Closure $stop,
    ) {
    }

    /**
     * Says a line in the conversation.
     */
    public function say(string $text): void
    {
        $this->view->showNotice($text);
    }

    /**
     * Says a line that reports something did not go as it should.
     */
    public function warn(string $text): void
    {
        $this->view->showError($text);
    }

    /**
     * Puts a prompt to the Agent as though the person had written it, and
     * finishes: the answer arrives on the screen, not here.
     */
    public function promptAgent(string $prompt): void
    {
        ($this->putToAgent)($prompt);
    }

    public function requestSelection(SelectionRequest $request): void
    {
        ($this->select)($request);
    }

    /**
     * The Agent answering the conversation.
     *
     * Its provider, its instructions and its tools are the Host Application's
     * own business, changed through the Neuron AI API rather than through
     * verbs duplicated here. The Conversation Runtime owns its History
     * through Sessions. After another Agent has been put in charge, this is
     * that one.
     */
    public function agent(): Agent
    {
        return ($this->answering)();
    }

    /**
     * Puts another Agent in charge of answering from here on.
     *
     * A conversation belongs to nobody in particular, so the one under way
     * moves over: the new Agent is handed the History the old one was
     * answering, and carries it on from the next turn. A command that knows
     * the two are not interchangeable — other tools, another provider —
     * installs a fresh History on the new Agent afterwards.
     */
    public function useAgent(Agent $agent): void
    {
        ($this->answerFrom)($agent);
    }

    /**
     * The commands mounted on this terminal, in the order they were mounted.
     *
     * The list contains the command reading it, which is why it arrives from
     * here rather than from the construction of a command: the Conversation
     * TUI is the one that has it, and a Host Application would have to build
     * it before it existed.
     *
     */
    public function commands(): Commands
    {
        return $this->mounted;
    }

    /**
     * The same Sessions instance supplied to this Conversation Runtime.
     */
    public function sessions(): Sessions
    {
        return $this->sessions;
    }

    /**
     * Leaves the terminal.
     */
    public function stop(): void
    {
        ($this->stop)();
    }
}
