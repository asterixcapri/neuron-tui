<?php

declare(strict_types=1);

namespace NeuronTui\View;

use NeuronInteraction\Command\CommandInput;
use NeuronInteraction\Command\SelectionOption;
use NeuronInteraction\Event\SelectionRequest;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Event\ChangeEvent;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\ParentInterface;
use Symfony\Component\Tui\Widget\Util\StringUtils;

use function array_slice;
use function count;
use function intdiv;
use function max;
use function mb_strtolower;
use function min;
use function str_contains;
use function str_repeat;
use function str_replace;
use function trim;

/** @internal */
final class SelectionView extends AbstractWidget implements ParentInterface
{
    private ?SelectionRequest $request = null;
    private int $selected = 0;
    private readonly InputWidget $search;

    /** @var list<SelectionOption> */
    private array $matches = [];

    public function __construct()
    {
        $this->search = (new InputWidget())->setPrompt('⌕ ');
        $this->search->onChange(fn(ChangeEvent $event) => $this->filter());
        $this->show(null);
    }

    public function show(?SelectionRequest $request): void
    {
        $wasVisible = $this->request !== null;
        $this->request = $request;
        if (!$wasVisible && $request !== null) {
            $this->getContext()?->attachChild($this, $this->search);
        } elseif ($wasVisible && $request === null && $this->getContext() !== null) {
            $this->getContext()->detachChild($this->search);
            $this->search->onChange(fn(ChangeEvent $event) => $this->filter());
        }
        $this->search->setValue('');
        $this->filter();
        $this->setStyle(new Style(hidden: $request === null));
    }

    /** @return list<AbstractWidget> */
    public function all(): array
    {
        return $this->request === null ? [] : [$this->search];
    }

    public function search(): InputWidget
    {
        return $this->search;
    }

    public function handleInput(string $data): void
    {
        $this->search->handleInput($data);
        $this->invalidate();
    }

    private function filter(): void
    {
        $this->matches = [];
        $this->selected = 0;
        $query = mb_strtolower(trim($this->search->getValue()));
        if ($this->request === null) {
            $this->invalidate();
            return;
        }
        foreach ($this->request->options as $option) {
            $text = self::singleLine($option->label . ' ' . ($option->description ?? ''));
            if ($query === '' || str_contains(mb_strtolower($text), $query)) {
                $this->matches[] = $option;
            }
        }
        $this->invalidate();
    }

    public function move(int $direction): void
    {
        $total = count($this->matches);
        if ($total === 0) {
            return;
        }
        $this->selected = ($this->selected + $direction + $total) % $total;
        $this->invalidate();
    }

    public function selectedInput(): ?CommandInput
    {
        if ($this->request === null || $this->matches === []) {
            return null;
        }
        return new CommandInput($this->request->command, $this->matches[$this->selected]->value);
    }

    /** @return list<string> */
    public function render(RenderContext $context): array
    {
        if ($this->request === null) {
            return [];
        }
        $columns = max(1, $context->getColumns());
        $indent = $columns >= 12 ? '  ' : '';
        $total = count($this->matches);
        $position = $total === 0 ? 0 : $this->selected + 1;
        $lines = [$this->applyElement('separator', str_repeat('─', $columns)), ''];
        $lines[] = $this->line('title', $indent . self::singleLine($this->request->prompt)
            . " ({$position}/{$total})", $columns);
        if ($this->request->description !== null) {
            $lines[] = $this->line('description', $indent . self::singleLine($this->request->description), $columns);
        }
        $lines[] = '';
        $searchWidth = max(1, $columns - AnsiUtils::visibleWidth($indent) - 2);
        $lines[] = $this->line('border', $indent . '╭' . str_repeat('─', $searchWidth) . '╮', $columns);
        if ($this->search->getValue() === '') {
            $search = '⌕ ' . ($this->search->isFocused() ? AnsiUtils::cursorMarker() : '') . 'Search…';
            $search = $this->applyElement('description', AnsiUtils::truncateToWidth($search, $searchWidth, '', true));
        } else {
            $search = $this->applyElement('search', $this->search->render($context->withColumns($searchWidth))[0]);
        }
        $lines[] = AnsiUtils::truncateToWidth(
            $this->applyElement('border', $indent . '│') . $search . $this->applyElement('border', '│'),
            $columns,
            '',
        );
        $lines[] = $this->line('border', $indent . '╰' . str_repeat('─', $searchWidth) . '╯', $columns);
        $lines[] = '';
        $terminalRows = $this->getContext()?->getTerminalRows() ?? max(18, $context->getRows());
        $budget = max(12, intdiv($terminalRows, 2));
        $limit = min(5, max(1, intdiv($budget - count($lines) - 2, 3)));
        $offset = max(0, $this->selected - $limit + 1);
        foreach (array_slice($this->matches, $offset, $limit) as $index => $option) {
            $selected = $index + $offset === $this->selected;
            $lines[] = $this->line(
                $selected ? 'selected' : 'label',
                $indent . ($selected ? '❯ ' : '  ') . self::singleLine($option->label),
                $columns
            );
            if ($option->description !== null) {
                $lines[] = $this->line(
                    'description',
                    $indent . '  ' . self::singleLine($option->description),
                    $columns,
                );
            }
            $lines[] = '';
        }
        if ($this->matches === []) {
            $lines[] = $this->line('description', $indent . '  No matching options.', $columns);
            $lines[] = '';
        }
        $lines[] = $this->line(
            'description',
            $indent . '↑/↓ choose · Enter confirm · Type to search · Esc cancel',
            $columns,
        );
        return $lines;
    }

    private function line(string $element, string $text, int $columns): string
    {
        return $this->applyElement($element, AnsiUtils::truncateToWidth($text, $columns, ''));
    }

    private static function singleLine(string $text): string
    {
        return str_replace(["\r", "\n", "\t"], ' ', StringUtils::stripControlBytes($text));
    }
}
