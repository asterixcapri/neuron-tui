<?php

declare(strict_types=1);

namespace NeuronTui\Conversation;

use NeuronChatCore\Conversation\ConversationEvent;
use NeuronChatCore\Conversation\EventType;
use NeuronChatCore\Conversation\TurnOutcome;
use NeuronTui\View\ConversationView;
use NeuronTui\View\DisplayableText;
use NeuronTui\View\ToolActivity;

use function microtime;
use function trim;

/** Terminal-only policy for rendering one response. @internal */
final class TurnEventRenderer
{
    private ?ToolActivity $toolActivity = null;
    private string $responseText = '';
    private string $pendingText = '';
    private ?string $messageId = null;

    public function __construct(private readonly ConversationView $view) {}

    public function consume(ConversationEvent $event): void
    {
        $working = $this->view->workingIndicator();
        if ($event->type === EventType::TurnStarted) {
            $this->responseText = '';
            $this->pendingText = '';
            $this->messageId = null;
            $this->toolActivity = $this->view->beginAgentResponse();
            return;
        }
        if ($event->type === EventType::ToolCalled && $event->tool !== null) {
            $this->view->endAgentMessage();
            $this->pendingText = '';
            $this->messageId = null;
            $working->whilePaused(microtime(true), function () use ($event): void {
                $this->toolActivity?->start($event->tool);
            });
            $this->view->paintPendingChanges();
            return;
        }
        if ($event->type === EventType::ToolResult && $event->tool !== null) {
            $working->whilePaused(microtime(true), function () use ($event): void {
                $this->toolActivity?->finish($event->tool);
            });
            $this->view->paintPendingChanges();
            return;
        }
        if ($event->type === EventType::Text) {
            if ($this->messageId !== null && $event->messageId !== $this->messageId) {
                $this->view->endAgentMessage();
                $this->pendingText = '';
            }
            $this->messageId = $event->messageId;
            $this->responseText .= $event->text ?? '';
            $this->pendingText .= $event->text ?? '';
            if (trim(DisplayableText::safe($this->pendingText)) !== '') {
                $text = $this->pendingText;
                $this->pendingText = '';
                $working->whilePaused(microtime(true), function () use ($text): void {
                    $this->view->appendAgentText($text);
                });
                $this->view->paintPendingChanges();
            }
            return;
        }
        if ($event->type === EventType::Error) {
            $this->view->showError($event->errorClass . ': ' . $event->text);
            return;
        }
        if ($event->type !== EventType::TurnEnded) {
            return;
        }
        if ($event->outcome === TurnOutcome::ApprovalPaused) {
            $this->view->showError('Human-in-the-loop interruptions are not supported.');
        } elseif ($event->outcome === TurnOutcome::Stopped) {
            $working->stop();
            $this->view->showResponseStopped();
        } elseif ($event->outcome === TurnOutcome::Completed && trim(DisplayableText::safe($this->responseText)) === '' && !($this->toolActivity?->hasActivity() ?? false)) {
            $working->stop();
            $this->view->showEmptyResponse();
        }
    }
}
