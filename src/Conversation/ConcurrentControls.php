<?php

declare(strict_types=1);

namespace NeuronTui\Conversation;

use Closure;
use NeuronTui\Command\Commands;
use NeuronTui\Tui\ConversationView;

/**
 * What a Concurrent command may do while it runs.
 *
 * The four verbs that keep their meaning while a Turn is under way: a command
 * holding these cannot open a Picker, cannot put a prompt to the Agent and
 * cannot reach the Agent or Sessions at all. It can report, list what may be
 * typed here, and leave.
 */
final readonly class ConcurrentControls
{
    /**
     * @param Closure(): Commands $mounted the commands mounted here
     *
     * @internal the Conversation TUI builds these, a command receives them
     */
    public function __construct(
        private ConversationView $view,
        private Closure $mounted,
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
     * Reading what may be typed here keeps its meaning while an answer is on
     * its way, which is why `/help` is a Concurrent command.
     *
     */
    public function commands(): Commands
    {
        return ($this->mounted)();
    }

    /**
     * Leaves the terminal, answer under way or not.
     */
    public function stop(): void
    {
        $this->view->stop();
    }
}
