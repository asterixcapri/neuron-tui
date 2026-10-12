<?php

declare(strict_types=1);

namespace NeuronTui;

use LogicException;
use NeuronInteraction\Command\CommandInput;
use NeuronInteraction\Conversation;
use NeuronTui\View\MainView;
use NeuronTui\View\Theme;
use RuntimeException;
use Symfony\Component\Tui\Terminal\Terminal;
use Symfony\Component\Tui\Tui as SymfonyTui;

use function stream_isatty;

use const STDIN;
use const STDOUT;

final class Tui
{
    private readonly MainView $mainView;
    private SymfonyTui $tui;
    private bool $started = false;

    private function __construct(private readonly Conversation $conversation)
    {
        $this->mainView = new MainView();
        $this->tui = new SymfonyTui();
        $this->wire();
    }

    public static function make(Conversation $conversation): self
    {
        return new self($conversation);
    }

    public function setTitle(string $title): self
    {
        $this->mainView->setTitle($title);

        return $this;
    }

    public function setDescription(string $description): self
    {
        $this->mainView->setDescription($description);

        return $this;
    }

    private function wire(): void
    {
        $this->mainView->onInput(function (string|CommandInput $input): void {
            (new TurnRunner($this->conversation, $this->mainView, $this->tui))->run($input);
        });
        $this->mainView->onCommands(fn(): array => $this->conversation->commands()->all());
        $this->mainView->onHistorySync(fn(): array => $this->conversation->getDisplayMessages());
        $this->mainView->onInputHistory(fn(): ?array => $this->conversation->inputHistory()?->list());
        $this->mainView->onEscape(fn(): bool => $this->conversation->requestInterruption());
    }

    public function run(): void
    {
        if ($this->started) {
            throw new LogicException('A TUI instance can only run once.');
        }
        if ($this->tui->getTerminal() instanceof Terminal && (!stream_isatty(STDIN) || !stream_isatty(STDOUT))) {
            throw new RuntimeException('Neuron TUI requires an interactive TTY.');
        }
        $this->started = true;
        $this->tui->addStyleSheet(Theme::styleSheet());
        $this->tui->add($this->mainView);
        $this->tui->addListener($this->mainView->handleInput(...));
        $this->mainView->focus();
        $this->mainView->syncHistory();
        try {
            $this->tui->run();
        } finally {
            $this->mainView->stop();
        }
    }
}
