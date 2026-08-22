<?php

declare(strict_types=1);

namespace NeuronCli\Conversation;

use Closure;
use NeuronAI\Agent\Agent;
use NeuronCli\Tui\ConversationView;

/**
 * What a Slash command may do while it runs.
 *
 * A command is code the Conversation TUI did not write, so it is given verbs
 * rather than the terminal: the widgets behind them stay out of reach and
 * remain free to change. Changing what an Agent is made of has no verb here,
 * because the Agent belongs to the Host Application and Neuron AI already
 * says how it is changed; only which Agent answers does, because that one is
 * the Conversation TUI's own to remember.
 */
final readonly class Controls
{
    /**
     * @param Closure(): Agent      $answering  the Agent answering right now
     * @param Closure(string): void $putToAgent how a prompt reaches the Agent
     * @param Closure(Agent): void  $answerFrom how another Agent takes over
     * @param list<Command>         $mounted    the commands mounted here
     *
     * @internal the Conversation TUI builds these, a command receives them
     */
    public function __construct(
        private ConversationView $view,
        private Closure $answering,
        private Closure $putToAgent,
        private Closure $answerFrom,
        private array $mounted,
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
    public function ask(string $prompt): void
    {
        ($this->putToAgent)($prompt);
    }

    /**
     * Offers a list and waits there for what a person chose.
     *
     * The only verb that waits: it returns the key of the line chosen, or
     * nothing at all if a person cancelled. What the keys stand for is the
     * command's own business — the list shows the labels and hands the key
     * back untouched. The terminal goes on painting meanwhile.
     *
     * @param non-empty-list<ChoiceOption> $options in the order the lines are
     *                                                 offered
     */
    public function choose(string $title, array $options): ?string
    {
        return $this->view->choose($title, $options);
    }

    /**
     * The Agent answering the conversation.
     *
     * Its provider, its instructions, its tools and its History are the Host
     * Application's own business, changed through the Neuron AI API rather
     * than through verbs duplicated here. After another Agent has been put
     * in charge, this is that one.
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
     * @return list<Command>
     */
    public function commands(): array
    {
        return $this->mounted;
    }

    /**
     * Leaves the terminal.
     */
    public function stop(): void
    {
        $this->view->stop();
    }
}
