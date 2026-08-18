<?php

declare(strict_types=1);

namespace NeuronCli\Conversation;

use NeuronCli\Tui\ConversationView;

/**
 * What a command allowed to run while the Agent is working may do.
 *
 * The four verbs that ask nothing of the conversation: a command holding
 * these cannot open a Picker, cannot put a prompt to the Agent and cannot
 * reach it at all, because it may well be running while the Agent is
 * answering. What it can do is report, list what may be typed here, and
 * leave.
 *
 * These are not the Controls with some verbs closed off at runtime: they are
 * fewer verbs, so a command of that kind is stopped where it is written
 * rather than where it runs.
 */
final readonly class LimitedControls
{
    /**
     * @param list<Command> $mounted the commands mounted here
     *
     * @internal the Conversation TUI builds these, a command receives them
     */
    public function __construct(
        private ConversationView $view,
        private array $mounted,
    ) {
    }

    /**
     * Says a line in the conversation, below whatever is being answered.
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
     * The commands mounted on this terminal, in the order they were mounted.
     *
     * Reading what may be typed here is one of the things that keep their
     * meaning while an answer is on its way, which is why `/help` is among
     * the commands that run then.
     *
     * @return list<Command>
     */
    public function commands(): array
    {
        return $this->mounted;
    }

    /**
     * Leaves the terminal, answer under way or not.
     */
    public function stop(): void
    {
        $this->view->stop();
    }
}
