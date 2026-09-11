<?php

declare(strict_types=1);

namespace NeuronTui\Conversation;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronTui\History\InterruptionHistory;
use NeuronTui\View\ConversationView;
use NeuronTui\View\DisplayableText;
use NeuronTui\View\ToolActivity;
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
    public function run(Agent $agent, string $message, ?TurnInterruption $interruption = null): void
    {
        $interruption ??= new TurnInterruption();
        InterruptionHistory::prepare($agent->getChatHistory());
        $toolActivity = $this->view->beginAgentResponse();
        $responseText = '';
        $pendingAgentText = '';
        $userMessage = new UserMessage($message);

        if ($interruption->isRequested()) {
            $interruption->finish();
            $this->finishInterrupted($agent, $userMessage, '');

            return;
        }

        $events = $agent
            ->stream($userMessage)
            ->events();

        // Advancing the generator can start provider or tool work. Yield to
        // terminal input before asking Neuron for the next event.
        while ($events->valid()) {
            $event = $events->current();

            if ($interruption->checkpoint()) {
                $interruption->finish();
                $this->finishInterrupted($agent, $userMessage, $responseText);

                return;
            }

            // Leave the current event suspended until its presentation has
            // completed, and until buffered input has had a chance to run.
            if ($event instanceof StreamChunk) {
                $this->presentEvent($event, $responseText, $pendingAgentText, $toolActivity);
            }

            if ($interruption->checkpoint()) {
                $interruption->finish();
                $this->finishInterrupted($agent, $userMessage, $responseText);

                return;
            }

            $events->next();
        }

        // A provider can suspend after its final chunk, then commit its
        // response without yielding another event. An accepted request still
        // owns that outcome, even when generator completion follows it.
        if ($interruption->finish()) {
            $this->finishInterrupted($agent, $userMessage, $responseText, true);

            return;
        }

        $displayableText = DisplayableText::safe($responseText);

        if (trim($displayableText) === '' && !$toolActivity->hasActivity()) {
            $this->workingIndicator->stop();
            $this->view->showEmptyResponse();
        }
    }

    private function presentEvent(
        StreamChunk $event,
        string &$responseText,
        string &$pendingAgentText,
        ToolActivity $toolActivity,
    ): void {
        if ($event instanceof ToolCallChunk) {
            $this->view->endAgentMessage();
            $responseText = '';
            $pendingAgentText = '';
            $this->workingIndicator->whilePaused(
                microtime(true),
                static function () use ($toolActivity, $event): void {
                    $toolActivity->start($event->tool);
                },
            );
            $this->view->paintPendingChanges();

            return;
        }

        if ($event instanceof ToolResultChunk) {
            $this->workingIndicator->whilePaused(
                microtime(true),
                static function () use ($toolActivity, $event): void {
                    $toolActivity->finish($event->tool);
                },
            );
            $this->view->paintPendingChanges();

            return;
        }

        if (!$event instanceof TextChunk) {
            return;
        }

        $responseText .= $event->content;
        $pendingAgentText .= $event->content;

        if (trim(DisplayableText::safe($pendingAgentText)) === '') {
            return;
        }

        $text = $pendingAgentText;
        $pendingAgentText = '';
        $this->workingIndicator->whilePaused(
            microtime(true),
            function () use ($text): void {
                $this->view->appendAgentText($text);
            },
        );
        $this->view->paintPendingChanges();
    }

    private function finishInterrupted(Agent $agent, UserMessage $userMessage, string $responseText, bool $completed = false): void
    {
        $history = $agent->getChatHistory();
        $hasText = trim(DisplayableText::safe($responseText)) !== '';

        if ($completed) {
            $messages = $history->getMessages();
            $last = end($messages);

            if ($last instanceof AssistantMessage && !$last instanceof ToolCallMessage) {
                array_pop($messages);
                $history->flushAll();

                foreach ($messages as $retained) {
                    if ($retained === $userMessage && !$hasText) {
                        $retained->addMetadata('stop_reason', 'interrupted');
                    }

                    $history->addMessage($retained);
                }
            }
        }

        if (!in_array($userMessage, $history->getMessages(), true)) {
            if (!$hasText) {
                $userMessage->addMetadata('stop_reason', 'interrupted');
            }

            $history->addMessage($userMessage);
        }

        if ($hasText) {
            $history->addMessage((new AssistantMessage($responseText))->setStopReason('interrupted'));
        }

        $this->workingIndicator->stop();
        $this->view->showTurnInterrupted();
    }
}
