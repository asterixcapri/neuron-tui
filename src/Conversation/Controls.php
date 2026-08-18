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
 * remain free to change. Changing the Agent itself has no verb here,
 * because the Agent belongs to the Host Application and Neuron AI already
 * says how it is changed.
 */
final readonly class Controls
{
    /**
     * @param Closure(string): void $putToAgent how a prompt reaches the Agent
     *
     * @internal the Conversation TUI builds these, a command receives them
     */
    public function __construct(
        private ConversationView $view,
        private Agent $agent,
        private Closure $putToAgent,
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
     * @param array<string, string> $options key => label, in the order the
     *                                       lines are offered
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
     * than through verbs duplicated here.
     */
    public function agent(): Agent
    {
        return $this->agent;
    }

    /**
     * Leaves the terminal.
     */
    public function stop(): void
    {
        $this->view->stop();
    }
}
