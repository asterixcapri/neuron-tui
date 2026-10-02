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
    private string $responseText = '';
    private string $pendingText = '';
    private ?string $messageId = null;

    public function __construct(private readonly ConversationView $view) {}

    /**
     * @param Generator<int, object, mixed, AgentState> $stream
     * @param (Closure(): bool)|null $responseWasStopped
     */
    public function run(Generator $stream, ?Closure $responseWasStopped = null): bool
    {
        $this->responseText = '';
        $this->pendingText = '';
        $this->messageId = null;
        $this->toolActivity = $this->view->beginAgentResponse();
        try {
            foreach ($stream as $chunk) {
                $this->consume($chunk);
            }
        } catch (Throwable $error) {
            $this->view->showError($error::class . ': ' . $error->getMessage());
            if ($responseWasStopped?->__invoke() ?? false) {
                $this->view->workingIndicator()->stop();
                $this->view->showResponseStopped();
            }
            throw $error;
        }

        $working = $this->view->workingIndicator();
        if ($responseWasStopped?->__invoke() ?? false) {
            $working->stop();
            $this->view->showResponseStopped();
            return false;
        }
        if ($stream->getReturn()->isInterrupted()) {
            $this->view->showError('Human-in-the-loop interruptions are not supported.');
            return false;
        }
        if (trim(DisplayableText::safe($this->responseText)) === '' && !($this->toolActivity?->hasActivity() ?? false)) {
            $working->stop();
            $this->view->showEmptyResponse();
        }
        return true;
    }

    private function consume(object $chunk): void
    {
        $working = $this->view->workingIndicator();
        if ($chunk instanceof ToolCallChunk) {
            $this->view->endAgentMessage();
            $this->pendingText = '';
            $this->messageId = null;
            $working->whilePaused(microtime(true), function () use ($chunk): void {
                $this->toolActivity?->start($chunk->tool);
            });
            $this->view->paintPendingChanges();
            return;
        }
        if ($chunk instanceof ToolResultChunk) {
            $working->whilePaused(microtime(true), function () use ($chunk): void {
                $this->toolActivity?->finish($chunk->tool);
            });
            $this->view->paintPendingChanges();
            return;
        }
        if ($chunk instanceof TextChunk) {
            if ($this->messageId !== null && $chunk->messageId !== $this->messageId) {
                $this->view->endAgentMessage();
                $this->pendingText = '';
            }
            $this->messageId = $chunk->messageId;
            $this->responseText .= $chunk->content;
            $this->pendingText .= $chunk->content;
            if (trim(DisplayableText::safe($this->pendingText)) !== '') {
                $text = $this->pendingText;
                $this->pendingText = '';
                $working->whilePaused(microtime(true), function () use ($text): void {
                    $this->view->appendAgentText($text);
                });
                $this->view->paintPendingChanges();
            }
        }
    }
}
