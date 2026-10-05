<?php

declare(strict_types=1);

namespace NeuronTui;

use LogicException;
use NeuronAI\Agent\Agent;
use NeuronTui\View\MainView;
use NeuronTui\View\Theme;
use RuntimeException;
use Symfony\Component\Tui\Terminal\Terminal;
use Symfony\Component\Tui\Tui as SymfonyTui;

use function bin2hex;
use function random_bytes;
use function stream_isatty;

use const STDIN;
use const STDOUT;

final class Tui
{
    private readonly MainView $mainView;
    private SymfonyTui $tui;
    private bool $started = false;

    private function __construct(private readonly Agent $agent)
    {
        if ($this->agent->getThreadId() === null) {
            $this->agent->setThreadId(bin2hex(random_bytes(16)));
        }
        $this->mainView = new MainView();
        $this->tui = new SymfonyTui();
        $this->wire();
    }

    public static function make(Agent $agent): self
    {
        return new self($agent);
    }

    private function wire(): void
    {
        $this->mainView->onInput(function (string $input): void {
            (new TurnRunner($this->agent, $this->mainView, $this->tui))->run($input);
        });
        $this->mainView->onHistorySync(fn(): array => $this->agent->getChatHistory()->getMessages());
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
        $terminal = $this->tui->getTerminal();
        try {
            $terminal->write("\x1b[?1000h\x1b[?1006h");
            $this->tui->run();
        } finally {
            $terminal->write("\x1b[?1006l\x1b[?1000l");
        }
    }
}
