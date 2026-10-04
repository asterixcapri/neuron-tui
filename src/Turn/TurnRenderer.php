<?php

declare(strict_types=1);

namespace NeuronTui\Turn;

use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronTui\View\ConversationView;
use NeuronTui\View\DisplayableText;
use NeuronTui\View\ToolActivity;

use function microtime;
use function trim;

/** Renders text and tool activity for one stream; event routing belongs to TurnRunner. @internal */
final class TurnRenderer
{
    private ?ToolActivity $toolActivity = null;
    private bool $hasVisibleText = false;
    private string $pendingText = '';
    private ?string $messageId = null;

    public function __construct(private readonly ConversationView $view) {}

    public function beginResponse(): void
    {
        $this->toolActivity ??= $this->view->beginAgentResponse();
    }

    public function finish(): void
    {
        if (!$this->hasVisibleText && !($this->toolActivity?->hasActivity() ?? false)) {
            $this->beginResponse();
            $this->view->workingIndicator()->stop();
            $this->view->showEmptyResponse();
        }
    }

    public function toolCall(ToolCallChunk $chunk): void
    {
        $this->beginResponse();
        $this->view->endAgentMessage();
        $this->pendingText = '';
        $this->messageId = null;
        $this->view->workingIndicator()->whilePaused(microtime(true), function () use ($chunk): void {
            $this->toolActivity?->start($chunk->tool);
        });
        $this->view->paintPendingChanges();
    }

    public function toolResult(ToolResultChunk $chunk): void
    {
        $this->beginResponse();
        $this->view->workingIndicator()->whilePaused(microtime(true), function () use ($chunk): void {
            $this->toolActivity?->finish($chunk->tool);
        });
        $this->view->paintPendingChanges();
    }

    public function text(TextChunk $chunk): void
    {
        $this->beginResponse();
        if ($this->messageId !== null && $chunk->messageId !== $this->messageId) {
            $this->view->endAgentMessage();
            $this->pendingText = '';
        }
        $this->messageId = $chunk->messageId;
        $this->pendingText .= $chunk->content;
        if (trim(DisplayableText::safe($this->pendingText)) === '') {
            return;
        }

        $text = $this->pendingText;
        $this->pendingText = '';
        $this->hasVisibleText = true;
        $this->view->workingIndicator()->whilePaused(microtime(true), function () use ($text): void {
            $this->view->appendAgentText($text);
        });
        $this->view->paintPendingChanges();
    }
}
