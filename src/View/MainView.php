<?php

declare(strict_types=1);

namespace NeuronTui\View;

use Closure;
use LogicException;
use NeuronAI\Chat\Messages\Message;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Style\Padding;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Throwable;

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

    public function __construct()
    {
        $this->history = new HistoryView();
        $this->header = new HeaderView();
        $this->composer = new ComposerView();
        $this->keys = new Keybindings([
            'stop' => [Key::ESCAPE],
            'quit' => ['ctrl+c'],
        ]);
        $this->setStyle(new Style(padding: Padding::xy(2), gap: 1));
        $this->add($this->header)->add($this->history)->add($this->composer);
        $this->composer->onSubmit($this->submit(...));
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
            $this->getContext()?->stop();
        } elseif ($this->keys->matches($data, 'stop') && $this->busy) {
            $this->onEscape?->__invoke();
            $event->stopPropagation();
        } elseif ($this->busy) {
            $event->stopPropagation();
        }
    }

    private function submit(SubmitEvent $event): void
    {
        if ($event->isBlank() || $this->busy || $this->onInput === null) {
            return;
        }
        $prompt = $event->getValue();
        $this->busy = true;
        $this->composer->beginTurn();
        $this->history->beginTurn($prompt);
        $this->getContext()?->requestRender();

        try {
            ($this->onInput)($prompt);
        } catch (Throwable $error) {
            $this->notify('Error › ' . $error->getMessage());
            $this->finishTurn();
        }
    }

    public function stop(): void
    {
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

    public function notify(string $text): void
    {
        if (!$this->busy) {
            throw new LogicException('No turn is in progress.');
        }
        $this->history->notify($text);
        $this->getContext()?->requestRender();
    }

    public function finishTurn(): void
    {
        $this->history->finishTurn();
        $this->composer->finishTurn();
        $this->busy = false;
        $this->focus();
        $this->getContext()?->requestRender();
    }

}
