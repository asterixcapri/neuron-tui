<?php

declare(strict_types=1);

namespace NeuronTui\Conversation;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronTui\View\ConversationView;
use NeuronTui\View\DisplayableText;
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
        $toolActivity = $this->view->beginAgentResponse();
        $responseText = '';
        $pendingAgentText = '';
        $userMessage = new UserMessage($message);
        $toolCall = null;
        $toolResults = [];
        $interrupted = false;
        $failure = null;

        $events = $agent
            ->stream($userMessage)
            ->events();

        try {
            foreach ($events as $event) {
                if ($event instanceof ToolCallChunk) {
                    // Neuron has committed the User and the complete call group.
                    // Its tools keep running normally while interruption is pending.
                    $messages = $agent->getChatHistory()->getMessages();
                    $last = end($messages);

                    if ($last instanceof ToolCallMessage && $last !== $toolCall) {
                        $toolCall = $last;
                        $toolResults = [];
                    }

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

                    continue;
                }

                if ($event instanceof ToolResultChunk) {
                    $toolResults[] = $event->tool;
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

                // A partial Assistant keeps Neuron's ordinary History valid.
                // Before any displayable text, wait for the first such chunk.
                if ($interruption->checkpoint() && trim(DisplayableText::safe($responseText)) !== '') {
                    $interrupted = true;

                    break;
                }

                $responseText .= $event->content;
                $pendingAgentText .= $event->content;

                if (trim(DisplayableText::safe($pendingAgentText)) === '') {
                    continue;
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

                if ($interruption->checkpoint()) {
                    $interrupted = true;

                    break;
                }
            }
        } catch (WorkflowInterrupt $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $failure = $exception;
            $interrupted = $interruption->checkpoint() && trim(DisplayableText::safe($responseText)) !== '';
        } finally {
            // A request overtaken by completion never rewrites committed History.
            $interruption->finish();
        }

        if ($interrupted) {
            $this->finishInterrupted($agent, $userMessage, $responseText, $toolCall, $toolResults);
        }

        if ($failure !== null) {
            throw $failure;
        }

        $displayableText = DisplayableText::safe($responseText);

        if (trim($displayableText) === '' && !$toolActivity->hasActivity()) {
            $this->workingIndicator->stop();
            $this->view->showEmptyResponse();
        }
    }

    /** @param list<ToolInterface> $toolResults */
    private function finishInterrupted(
        Agent $agent,
        UserMessage $userMessage,
        string $responseText,
        ?ToolCallMessage $toolCall,
        array $toolResults,
    ): void {
        $history = $agent->getChatHistory();

        if ($toolCall === null) {
            $history->addMessage($userMessage);
        } else {
            $messages = $history->getMessages();

            // These outcomes would normally commit after the next inference.
            if (end($messages) === $toolCall) {
                $history->addMessage(new ToolResultMessage($toolResults));
            }
        }

        $history->addMessage((new AssistantMessage($responseText))->setStopReason('interrupted'));
        $this->workingIndicator->stop();
        $this->view->showTurnInterrupted();
    }
}
