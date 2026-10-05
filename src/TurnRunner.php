<?php

declare(strict_types=1);

namespace NeuronTui;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronTui\View\MainView;
use Revolt\EventLoop;
use Symfony\Component\Tui\Tui as SymfonyTui;
use Throwable;

/** @internal */
final class TurnRunner
{
    public function __construct(
        private readonly Agent $agent,
        private readonly MainView $view,
        private readonly SymfonyTui $tui,
    ) {}

    public function run(string $input): void
    {
        EventLoop::queue(function () use ($input): void {
            if ($this->tui->isRunning()) {
                $this->doRun($input);
            }
        });
    }

    private function doRun(string $input): void
    {
        try {
            $this->tui->processRender();
            $stream = $this->agent->stream(new UserMessage($input));
            foreach ($stream as $chunk) {
                if (!$this->tui->isRunning()) {
                    return;
                }
                if ($chunk instanceof TextChunk) {
                    $this->view->appendResponse($chunk->content);
                } elseif ($chunk instanceof ToolCallChunk) {
                    $this->view->notify('Tool › ' . $chunk->tool->getName() . ' …');
                } elseif ($chunk instanceof ToolResultChunk) {
                    $this->view->notify('Tool › ' . $chunk->tool->getName() . ' completed');
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
            if ($stream->getReturn()->isInterrupted()) {
                $this->view->notify('Agent paused: approval or external tool results are required.');
            }
        } catch (Throwable $error) {
            $this->view->notify('Error › ' . $error->getMessage());
        } finally {
            $this->view->finishTurn();
        }
    }
}
