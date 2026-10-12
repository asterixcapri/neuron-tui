<?php

declare(strict_types=1);

namespace NeuronTui\View;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Tools\ToolCall;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\ContainerWidget;

use function spl_object_id;

/** @internal Growing History; the terminal owns its scrollback. */
final class HistoryView extends ContainerWidget
{
    private ?MessageView $response = null;

    /** @var array<string, ToolView> */
    private array $tools = [];

    public function __construct()
    {
        $this->setStyle(new Style(gap: 1));
        $this->expandVertically(true);
    }

    /** @param iterable<Message> $history */
    public function load(iterable $history): void
    {
        $this->response = null;
        $this->tools = [];
        $this->clear();
        foreach ($history as $message) {
            $content = $message->getContent();
            if ($content !== null && $content !== '') {
                $kind = match ($message->getRole()) {
                    'user' => MessageKind::User,
                    'assistant' => MessageKind::Agent,
                    default => MessageKind::System,
                };
                $this->append($content, $kind);
            }
            if ($message instanceof ToolCallMessage) {
                foreach ($message->getToolCalls() as $tool) {
                    $this->showToolCall($tool);
                }
            }
            if ($message instanceof ToolResultMessage) {
                foreach ($message->getToolCalls() as $tool) {
                    $this->showToolResult($tool);
                }
            }
        }
    }

    public function beginTurn(string $prompt): void
    {
        $this->append($prompt, MessageKind::User);
        $this->response = $this->append('', MessageKind::Agent);
    }

    public function appendResponse(string $text): void
    {
        $this->response ??= $this->append('', MessageKind::Agent);
        $this->response->setText($this->response->getText() . $text);
    }

    public function notify(string $text, MessageKind $kind = MessageKind::Notice): void
    {
        $this->finishTurn();
        $this->append($text, $kind);
    }

    public function showToolCall(ToolCall $tool): void
    {
        $this->finishTurn();
        $key = $this->toolKey($tool);
        if (!isset($this->tools[$key])) {
            $this->tools[$key] = new ToolView($tool);
            $this->add($this->tools[$key]);
        }
    }

    public function showToolResult(ToolCall $tool): void
    {
        $this->finishTurn();
        $this->showToolCall($tool);
        $this->tools[$this->toolKey($tool)]->complete($tool);
    }

    private function toolKey(ToolCall $tool): string
    {
        $id = $tool->getCallId();
        return $id !== null ? 'call:' . $id : 'object:' . spl_object_id($tool);
    }

    public function finishTurn(): void
    {
        if ($this->response !== null && $this->response->getText() === '') {
            $this->remove($this->response);
        }
        $this->response = null;
    }

    private function append(string $text, MessageKind $kind): MessageView
    {
        $widget = new MessageView($text, $kind);
        $this->add($widget);
        return $widget;
    }
}
