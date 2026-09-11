<?php

declare(strict_types=1);

namespace NeuronTui\Conversation;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Nodes\ParallelToolNode;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronTui\History\InterruptionHistory;
use NeuronTui\View\ConversationView;
use NeuronTui\View\DisplayableText;
use NeuronTui\View\ToolActivity;
use NeuronTui\View\WorkingIndicator;
use Throwable;

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
        $tools = null;

        if ($interruption->isRequested()) {
            $interruption->finish();
            $this->finishInterrupted($agent, $userMessage, '');

            return;
        }

        $events = $agent
            ->stream($userMessage)
            ->events();

        try {
            // Advancing the generator can start provider or tool work. Yield to
            // terminal input before asking Neuron for the next event.
            while ($events->valid()) {
                $event = $events->current();

                if ($event instanceof ToolCallChunk) {
                    $messages = $agent->getChatHistory()->getMessages();
                    $last = end($messages);

                    if ($last instanceof ToolCallMessage && ($tools === null || !$tools->belongsTo($last))) {
                        $tools?->restore();
                        $node = $agent->getNodeForEvent(ToolCallEvent::class);
                        $tools = new ToolExecutionGroup($last, $node instanceof ParallelToolNode ? $node : null);
                    }

                    // This inference's prose is already retained by ToolCallMessage.
                    $responseText = '';
                    $pendingAgentText = '';
                }

                if ($event instanceof ToolResultChunk) {
                    $event = new ToolResultChunk($tools?->record($event->tool) ?? $event->tool);
                    $this->view->toolRunning($tools?->isSettling() ?? false);
                    // Started work keeps its real outcome even when Escape was
                    // received during execution, before this boundary is reached.
                    $this->presentEvent($event, $responseText, $pendingAgentText, $toolActivity);
                }

                if ($interruption->checkpoint() && !($tools?->isSettling() ?? false)) {
                    $interruption->finish();
                    $this->reconcileTools($agent, $tools, $toolActivity);
                    $this->finishInterrupted($agent, $userMessage, $responseText);

                    return;
                }

                // Leave the current event suspended until its presentation has
                // completed, and until buffered input has had a chance to run.
                if ($event instanceof StreamChunk && !$event instanceof ToolResultChunk) {
                    $this->presentEvent($event, $responseText, $pendingAgentText, $toolActivity);
                }

                if ($interruption->checkpoint() && !($tools?->isSettling() ?? false)) {
                    $interruption->finish();
                    $this->reconcileTools($agent, $tools, $toolActivity);
                    $this->finishInterrupted($agent, $userMessage, $responseText);

                    return;
                }

                if ($event instanceof ToolCallChunk) {
                    $tools?->start($event->tool);
                    $this->view->toolRunning(true);
                }

                $tools?->throwIfFailed();

                try {
                    $events->next();
                } catch (WorkflowInterrupt $exception) {
                    throw $exception;
                } catch (Throwable $exception) {
                    // A synchronous tool can throw before buffered Escape has
                    // been dispatched. Observe input before deciding its outcome.
                    if (!$interruption->checkpoint() || $tools === null || !$tools->fail($exception)) {
                        throw $exception;
                    }

                    $interruption->finish();
                    $this->reconcileTools($agent, $tools, $toolActivity);
                    $this->finishInterrupted($agent, $userMessage, $responseText);

                    return;
                }
            }

            // A provider can suspend after its final chunk, then commit its
            // response without yielding another event. An accepted request still
            // owns that outcome, even when generator completion follows it.
            if ($interruption->finish()) {
                $this->reconcileTools($agent, $tools, $toolActivity);
                $this->finishInterrupted($agent, $userMessage, $responseText, true);

                return;
            }

            $displayableText = DisplayableText::safe($responseText);

            if (trim($displayableText) === '' && !$toolActivity->hasActivity()) {
                $this->workingIndicator->stop();
                $this->view->showEmptyResponse();
            }
        } finally {
            // Abandon the suspended workflow before restoring the handler on
            // the reusable Agent; it must never resume with a stale adapter.
            unset($events);
            $tools?->restore();
        }
    }

    private function reconcileTools(Agent $agent, ?ToolExecutionGroup $tools, ToolActivity $activity): void
    {
        if ($tools === null) {
            return;
        }

        $this->workingIndicator->whilePaused(microtime(true), static function () use ($agent, $tools, $activity): void {
            foreach ($tools->reconcile($agent->getChatHistory()) as $result) {
                $activity->finish($result);
            }
        });
        $this->view->toolRunning(false);
        $this->view->paintPendingChanges();
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
        $response = $hasText ? (new AssistantMessage($responseText))->setStopReason('interrupted') : null;

        if (!$hasText && ($completed || !in_array($userMessage, $history->getMessages(), true))) {
            $userMessage->addMetadata('stop_reason', 'interrupted');
        }

        if ($completed) {
            $messages = $history->getMessages();
            $last = end($messages);

            if ($last instanceof AssistantMessage && !$last instanceof ToolCallMessage) {
                if (InterruptionHistory::replaceCompletedResponse($history, $response)) {
                    $this->workingIndicator->stop();
                    $this->view->showTurnInterrupted();

                    return;
                }

                // Histories without snapshot persistence have no update
                // operation; retain their existing public-API fallback.
                array_pop($messages);
                $history->flushAll();

                foreach ($messages as $retained) {
                    $history->addMessage($retained);
                }
            }
        }

        if (!in_array($userMessage, $history->getMessages(), true)) {
            $history->addMessage($userMessage);
        }

        if ($response !== null) {
            $history->addMessage($response);
        }

        $this->workingIndicator->stop();
        $this->view->showTurnInterrupted();
    }
}
