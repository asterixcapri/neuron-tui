<?php

declare(strict_types=1);

namespace NeuronTui\Conversation;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronTui\View\ConversationView;
use NeuronTui\View\DisplayableText;
use NeuronTui\View\WorkingIndicator;

/**
 * Executes one Turn of the Agent and presents its stream as it arrives.
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
final class TurnRunner
{
    private readonly WorkingIndicator $workingIndicator;

    public function __construct(
        private readonly ConversationView $view,
    ) {
        $this->workingIndicator = $this->view->workingIndicator();
    }

    /**
     * Sends the message and shows the answer as it comes back.
     */
    public function run(Agent $agent, string $message): void
    {
        $toolActivity = $this->view->beginAgentResponse();
        $responseText = '';

        $events = $agent
            ->stream(new UserMessage($message))
            ->events();

        foreach ($events as $event) {
            if ($event instanceof ToolCallChunk) {
                $toolActivity->start($event->tool);
                $this->view->paintPendingChanges();

                continue;
            }

            if ($event instanceof ToolResultChunk) {
                $this->workingIndicator->whilePaused(
                    microtime(true),
                    static function () use ($toolActivity, $event): void {
                        $toolActivity->finish($event->tool);
                    },
                );
                $this->view->paintPendingChanges();

                continue;
            }

            if (!$event instanceof TextChunk) {
                continue;
            }

            $this->workingIndicator->stop();
            $responseText .= $event->content;
            $this->view->appendAgentText($event->content);
            $this->view->paintPendingChanges();
        }

        $displayableText = DisplayableText::safe($responseText);

        if (trim($displayableText) === '' && !$toolActivity->hasActivity()) {
            $this->workingIndicator->stop();
            $this->view->showEmptyResponse();
        }
    }
}
