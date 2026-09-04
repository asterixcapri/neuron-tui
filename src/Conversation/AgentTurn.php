<?php

declare(strict_types=1);

namespace NeuronTui\Conversation;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronTui\Tui\ConversationView;
use NeuronTui\Tui\DisplayableText;
use NeuronTui\Tui\WorkingIndicator;

/**
 * One turn of the Agent, read from its stream as it happens.
 *
 * Everything the Agent can say during a turn is understood here: text as it
 * arrives, a tool being called, a tool coming back, and the answer that
 * turned out to hold nothing a person can read. Whether an answer was empty
 * is decided on what was actually shown — text that survives being made
 * displayable, or tool activity — rather than on what the provider returned.
 *
 * The turn paints as it reads, so it belongs on the far side of the line the
 * turn queue draws: it can only be exercised against a provider, where the
 * queue needs neither one nor an event loop.
 *
 * Which Agent answers is not remembered here: it arrives with the message, so
 * that a turn already begun ends with the Agent that took it even when
 * another one has meanwhile been put in charge.
 *
 * @internal
 */
final class AgentTurn
{
    private readonly WorkingIndicator $workingIndicator;

    public function __construct(
        private readonly ConversationView $view,
        private readonly ConversationPort $conversation,
    ) {
        $this->workingIndicator = $this->view->workingIndicator();
    }

    /**
     * Sends the typed input and shows the answer as it comes back.
     */
    public function respond(Agent $agent, ConversationInputInterface $input): void
    {
        $tools = $this->view->beginAgentResponse();
        $contents = '';

        $events = $agent
            ->stream($input->message())
            ->events();

        foreach ($events as $event) {
            if ($event instanceof ToolCallChunk) {
                if ($event->tool instanceof ConversationSourceInterface) {
                    $event->tool->connect($this->conversation);
                }

                $tools->start($event->tool);
                $this->view->paintPendingChanges();

                continue;
            }

            if ($event instanceof ToolResultChunk) {
                $this->workingIndicator->whilePaused(
                    microtime(true),
                    static function () use ($tools, $event): void {
                        $tools->finish($event->tool);
                    },
                );
                $this->view->paintPendingChanges();

                continue;
            }

            if (!$event instanceof TextChunk) {
                continue;
            }

            $this->workingIndicator->stop();
            $contents .= $event->content;
            $this->view->appendAgentText($event->content);
            $this->view->paintPendingChanges();
        }

        $visibleContents = DisplayableText::safe($contents);

        if (trim($visibleContents) === '' && !$tools->hasActivity()) {
            $this->workingIndicator->stop();
            $this->view->showEmptyResponse();
        }
    }
}
