<?php

declare(strict_types=1);

namespace NeuronTui\View;

use Closure;
use LogicException;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Tools\ToolCall;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Throwable;

use function array_shift;
use function trim;

/** @internal */
final class MainView extends ContainerWidget
{
    private readonly HistoryView $history;
    private readonly HeaderView $header;
    private readonly ComposerView $composer;
    private readonly Keybindings $keys;

    /** @var (Closure(string): void)|null */
    private ?Closure $onInput = null;

    /** @var (Closure(): iterable<Message>)|null */
    private ?Closure $onHistorySync = null;

    /** @var (Closure(): void)|null */
    private ?Closure $onEscape = null;

    private bool $busy = false;
    private bool $stopped = false;

    /** @var list<string> */
    private array $pending = [];

    public function __construct()
    {
        $this->history = new HistoryView();
        $this->header = new HeaderView();
        $this->composer = new ComposerView();
        $this->keys = new Keybindings([
            'stop' => [Key::ESCAPE],
            'quit' => ['ctrl+c'],
        ]);
        $this->setStyle(new Style(gap: 1));
        $this->add($this->header)->add($this->history)->add($this->composer);
        $this->composer->onSubmit($this->submit(...));
    }

    public function setTitle(string $title): void
    {
        $this->header->setTitle($title);
        $this->getContext()?->requestRender();
    }

    public function setDescription(string $description): void
    {
        $this->header->setDescription($description);
        $this->getContext()?->requestRender();
    }

    public function syncHistory(): void
    {
        $this->history->load($this->onHistorySync === null ? [] : ($this->onHistorySync)());
    }

    public function focus(): void
    {
        $this->getContext()?->getFocusManager()->setFocus($this->composer->input());
    }

    public function handleInput(InputEvent $event): void
    {
        $data = $event->getData();
        if ($this->keys->matches($data, 'quit')) {
            $event->stopPropagation();
            $this->stop();
        } elseif ($this->keys->matches($data, 'stop') && $this->busy) {
            $this->onEscape?->__invoke();
            $event->stopPropagation();
        }
    }

    private function submit(SubmitEvent $event): void
    {
        if ($event->isBlank() || $this->stopped || $this->onInput === null) {
            return;
        }
        $prompt = trim($event->getValue());
        $this->composer->input()->setText('');
        if ($this->busy) {
            $this->pending[] = $prompt;
            $this->composer->setPendingMessages($this->pending);
            $this->getContext()?->requestRender();
            return;
        }
        $this->beginTurn($prompt);
    }

    private function beginTurn(string $prompt): void
    {
        $this->busy = true;
        $this->composer->beginTurn();
        $this->history->beginTurn($prompt);
        $this->getContext()?->requestRender();

        try {
            $this->onInput?->__invoke($prompt);
        } catch (Throwable $error) {
            $this->notify($error->getMessage(), MessageKind::Error);
            $this->finishTurn();
        }
    }

    public function stop(): void
    {
        $this->stopped = true;
        $this->pending = [];
        $this->composer->setPendingMessages($this->pending);
        $this->getContext()?->stop();
    }

    /** @param Closure(string): void $listener */
    public function onInput(Closure $listener): void
    {
        $this->onInput = $listener;
    }

    /** @param Closure(): iterable<Message> $listener */
    public function onHistorySync(Closure $listener): void
    {
        $this->onHistorySync = $listener;
    }

    /** @param Closure(): void $listener */
    public function onEscape(Closure $listener): void
    {
        $this->onEscape = $listener;
    }

    public function appendResponse(string $text): void
    {
        if (!$this->busy) {
            throw new LogicException('No turn is in progress.');
        }
        $this->history->appendResponse($text);
        $this->getContext()?->requestRender();
    }

    public function notify(string $text, MessageKind $kind = MessageKind::Notice): void
    {
        if (!$this->busy) {
            throw new LogicException('No turn is in progress.');
        }
        $this->history->notify($text, $kind);
        $this->getContext()?->requestRender();
    }

    public function showToolCall(ToolCall $tool): void
    {
        if (!$this->busy) {
            throw new LogicException('No turn is in progress.');
        }
        $this->history->showToolCall($tool);
        $this->getContext()?->requestRender();
    }

    public function showToolResult(ToolCall $tool): void
    {
        if (!$this->busy) {
            throw new LogicException('No turn is in progress.');
        }
        $this->history->showToolResult($tool);
        $this->getContext()?->requestRender();
    }

    public function finishTurn(): void
    {
        $this->history->finishTurn();
        $this->composer->finishTurn();
        $this->busy = false;
        if (!$this->stopped && $this->pending !== []) {
            $prompt = array_shift($this->pending);
            $this->composer->setPendingMessages($this->pending);
            $this->beginTurn($prompt);
        }
        $this->focus();
        $this->getContext()?->requestRender();
    }

}
