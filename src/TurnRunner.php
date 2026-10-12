<?php

declare(strict_types=1);

namespace NeuronTui;

use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronInteraction\Command\CommandInput;
use NeuronInteraction\Command\NotificationLevel;
use NeuronInteraction\Conversation;
use NeuronInteraction\Event\ExitRequest;
use NeuronInteraction\Event\Notification;
use NeuronInteraction\Event\SelectionRequest;
use NeuronInteraction\Event\SessionChanged;
use NeuronTui\View\MainView;
use NeuronTui\View\MessageKind;
use Revolt\EventLoop;
use Symfony\Component\Tui\Tui as SymfonyTui;
use Throwable;

/** @internal */
final class TurnRunner
{
    public function __construct(
        private readonly Conversation $conversation,
        private readonly MainView $view,
        private readonly SymfonyTui $tui,
    ) {}

    public function run(string|CommandInput $input): void
    {
        EventLoop::queue(function () use ($input): void {
            if ($this->tui->isRunning()) {
                $this->doRun($input);
            }
        });
    }

    private function doRun(string|CommandInput $input): void
    {
        try {
            $this->tui->processRender();
            $stream = $this->conversation->sendInput($input);
            foreach ($stream as $chunk) {
                if (!$this->tui->isRunning()) {
                    return;
                }
                if ($chunk instanceof TextChunk) {
                    $this->view->appendResponse($chunk->content);
                } elseif ($chunk instanceof ToolCallChunk) {
                    $this->view->showToolCall($chunk->tool);
                } elseif ($chunk instanceof ToolResultChunk) {
                    $this->view->showToolResult($chunk->tool);
                }
                if ($chunk instanceof Notification) {
                    $this->view->notify(
                        $chunk->text,
                        $chunk->level === NotificationLevel::Error ? MessageKind::Error : MessageKind::Notice,
                    );
                } elseif ($chunk instanceof ExitRequest) {
                    $this->view->stop();
                    return;
                } elseif ($chunk instanceof SessionChanged) {
                    $this->view->syncHistory();
                } elseif ($chunk instanceof SelectionRequest) {
                    $this->view->showSelection($chunk);
                }
                $this->tui->processRender();
                // Let terminal input and async providers progress between chunks.
                $suspension = EventLoop::getSuspension();
                EventLoop::delay(0, static fn() => $suspension->resume());
                $suspension->suspend();
                if (!$this->tui->isRunning()) {
                    return;
                }
            }
            if ($stream->getReturn()?->isInterrupted()) {
                $this->view->notify('Agent paused: approval or external tool results are required.');
            }
        } catch (Throwable $error) {
            $this->view->notify($error->getMessage(), MessageKind::Error);
        } finally {
            $this->view->finishTurn();
        }
    }
}
