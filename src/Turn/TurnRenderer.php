<?php

declare(strict_types=1);

namespace NeuronTui\Turn;

use Closure;
use Generator;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronTui\View\ConversationView;
use NeuronTui\View\DisplayableText;
use NeuronTui\View\ToolActivity;
use Throwable;

use function microtime;
use function trim;

/** Terminal-only policy for rendering one response. @internal */
final class TurnRenderer
{
    private ?ToolActivity $toolActivity = null;
    private bool $hasVisibleText = false;
    private string $pendingText = '';
    private ?string $messageId = null;

    public function __construct(private readonly ConversationView $view) {}

    /**
     * Errors are presented and rethrown; false means a stopped or interrupted response.
     *
     * @param Generator<int, object, mixed, AgentState|null> $stream
     * @param (Closure(): bool)|null $responseWasStopped
     * @param (Closure(object): bool)|null $interactionEvent
     */
    public function run(Generator $stream, ?Closure $responseWasStopped = null, ?Closure $interactionEvent = null): bool
    {
        $this->hasVisibleText = false;
        $this->pendingText = '';
        $this->messageId = null;
        $this->toolActivity = null;

        try {
            foreach ($stream as $chunk) {
                if ($interactionEvent?->__invoke($chunk) ?? false) {
                    continue;
                }
                $this->toolActivity ??= $this->view->beginAgentResponse();
                $this->consume($chunk);
            }

            $state = $stream->getReturn();
            if ($state === null) {
                return true;
            }

            if ($responseWasStopped?->__invoke() ?? false) {
                $this->showStoppedResponse();

                return false;
            }
            if ($state->isInterrupted()) {
                $this->view->showError('Human-in-the-loop interruptions are not supported.');

                return false;
            }
            if (!$this->hasVisibleText && !($this->toolActivity?->hasActivity() ?? false)) {
                $this->view->workingIndicator()->stop();
                $this->view->showEmptyResponse();
            }

            return true;
        } catch (Throwable $error) {
            $this->view->showError($error::class . ': ' . $error->getMessage());
            if ($responseWasStopped?->__invoke() ?? false) {
                $this->showStoppedResponse();
            }

            throw $error;
        }
    }

    private function showStoppedResponse(): void
    {
        $this->view->workingIndicator()->stop();
        $this->view->showResponseStopped();
    }

    private function consume(object $chunk): void
    {
        if ($chunk instanceof ToolCallChunk) {
            $this->consumeToolCall($chunk);
        } elseif ($chunk instanceof ToolResultChunk) {
            $this->consumeToolResult($chunk);
        } elseif ($chunk instanceof TextChunk) {
            $this->consumeText($chunk);
        }
    }

    private function consumeToolCall(ToolCallChunk $chunk): void
    {
        $this->view->endAgentMessage();
        $this->pendingText = '';
        $this->messageId = null;
        $this->view->workingIndicator()->whilePaused(microtime(true), function () use ($chunk): void {
            $this->toolActivity?->start($chunk->tool);
        });
        $this->view->paintPendingChanges();
    }

    private function consumeToolResult(ToolResultChunk $chunk): void
    {
        $this->view->workingIndicator()->whilePaused(microtime(true), function () use ($chunk): void {
            $this->toolActivity?->finish($chunk->tool);
        });
        $this->view->paintPendingChanges();
    }

    private function consumeText(TextChunk $chunk): void
    {
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
