<?php

declare(strict_types=1);

namespace NeuronCli\Conversation;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronCli\Tui\ConversationView;
use NeuronCli\Tui\DisplayableText;
use NeuronCli\Tui\WorkingIndicator;

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
 * @internal
 */
final class AgentTurn
{
    private readonly WorkingIndicator $workingIndicator;

    public function __construct(
        private readonly Agent $agent,
        private readonly ConversationView $view,
    ) {
        $this->workingIndicator = $this->view->workingIndicator();
    }

    /**
     * Sends the message and shows the answer as it comes back.
     */
    public function respond(string $message): void
    {
        $tools = $this->view->beginAgentResponse();
        $contents = '';

        $events = $this->agent
            ->stream(new UserMessage($message))
            ->events();

        foreach ($events as $event) {
            if ($event instanceof ToolCallChunk) {
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
