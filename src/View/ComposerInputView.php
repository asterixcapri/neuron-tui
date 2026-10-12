<?php

declare(strict_types=1);

namespace NeuronTui\View;

use Closure;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Event\ChangeEvent;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\EditorWidget;

use function array_values;
use function count;
use function explode;
use function max;
use function str_contains;

/** @internal */
final class ComposerInputView extends EditorWidget
{
    /** @var (Closure(): (list<string>|null))|null */
    private ?Closure $history = null;

    /** @var (Closure(): void)|null */
    private ?Closure $onRecall = null;

    /** @var list<string> */
    private array $entries = [];
    private ?int $position = null;
    private string $draft = '';

    public function __construct()
    {
        parent::__construct();
        $this->setMinVisibleLines(1)->setMaxVisibleLines(5)->setStyle(new Style(color: Theme::SECONDARY));
    }

    public function setText(string $text): static
    {
        $this->position = null;
        $this->entries = [];
        $changed = $text !== $this->getText();
        parent::setText($text);
        if ($changed) {
            $this->dispatch(new ChangeEvent($this, $this->getText()));
        }
        return $this;
    }

    /** @param Closure(): (list<string>|null) $history */
    public function onInputHistory(Closure $history): void
    {
        $this->history = $history;
    }

    /** @param Closure(): void $listener */
    public function onRecall(Closure $listener): void
    {
        $this->onRecall = $listener;
    }

    public function handleInput(string $data): void
    {
        $keys = $this->getKeybindings();
        $singleLine = !str_contains($this->getText(), "\n");
        if ($this->history !== null) {
            if ($keys->matches($data, 'history_previous') || ($singleLine && $keys->matches($data, 'cursor_up'))) {
                if ($this->recall(-1)) {
                    return;
                }
            }
            if ($keys->matches($data, 'history_next') || ($singleLine && $keys->matches($data, 'cursor_down'))) {
                if ($this->recall(1)) {
                    return;
                }
            }
        }

        $before = $this->getText();
        parent::handleInput($data);
        if ($this->getText() !== $before) {
            $this->position = null;
            $this->entries = [];
        }
    }

    private function recall(int $direction): bool
    {
        if ($this->position === null) {
            $entries = $this->history === null ? null : ($this->history)();
            if ($entries === null) {
                return false;
            }
            if ($direction > 0) {
                return true;
            }
            $this->entries = $entries;
            if ($this->entries === []) {
                return true;
            }
            $this->draft = $this->getText();
            $this->position = count($this->entries);
        }

        $this->position = max(0, $this->position + $direction);
        $text = $this->entries[$this->position] ?? $this->draft;
        parent::setText($text);
        // EditorWidget resets the cursor to the start when replacing text.
        foreach (explode("\n", $text) as $line) {
            parent::handleInput("\x1b[B");
        }
        $this->dispatch(new ChangeEvent($this, $this->getText()));
        if ($this->position >= count($this->entries)) {
            $this->position = null;
            $this->entries = [];
        } else {
            $this->onRecall?->__invoke();
        }
        return true;
    }

    /** @return array<string, string[]> */
    protected static function getDefaultKeybindings(): array
    {
        return parent::getDefaultKeybindings() + [
            'history_previous' => ['ctrl+p'],
            'history_next' => ['ctrl+n'],
        ];
    }

    /** @return list<string> */
    public function render(RenderContext $context): array
    {
        $columns = $context->getColumns();
        $lines = array_values(parent::render($context->withColumns(max(1, $columns - 2))));
        $last = count($lines) - 1;
        $prompt = (new Style(color: Theme::PROMPT))->apply('❯')
            . (new Style(color: Theme::SECONDARY))->getAnsiRestore() . ' ';
        $border = (new Style(color: Theme::BORDER))->apply('──');
        foreach ($lines as $index => $line) {
            $prefix = $index === 0 || $index === $last ? $border : ($index === 1 ? $prompt : '  ');
            $lines[$index] = AnsiUtils::truncateToWidth($prefix . $line, max(1, $columns), '');
        }

        return $lines;
    }
}
