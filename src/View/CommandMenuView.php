<?php

declare(strict_types=1);

namespace NeuronTui\View;

use Closure;
use NeuronInteraction\Command\CommandInterface;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\Util\StringUtils;

use function array_slice;
use function count;
use function intdiv;
use function max;
use function min;
use function preg_match;
use function str_replace;
use function str_starts_with;

/** @internal */
final class CommandMenuView extends AbstractWidget
{
    /** @var (Closure(): list<CommandInterface>)|null */
    private ?Closure $commands = null;

    /** @var list<CommandInterface> */
    private array $matches = [];
    private int $selected = 0;

    public function __construct()
    {
        $this->dismiss();
    }

    /** @param Closure(): list<CommandInterface> $commands */
    public function onCommands(Closure $commands): void
    {
        $this->commands = $commands;
    }

    public function update(string $text): void
    {
        $this->matches = [];
        $this->selected = 0;
        if ($this->commands !== null && preg_match('~^/[A-Za-z0-9_-]*$~D', $text) === 1) {
            foreach (($this->commands)() as $command) {
                if (str_starts_with($command->name(), $text)) {
                    $this->matches[] = $command;
                    if ($command->name() === $text) {
                        $this->selected = count($this->matches) - 1;
                    }
                }
            }
        }
        $this->setStyle(new Style(hidden: $this->matches === []));
    }

    public function selectedName(): ?string
    {
        return ($this->matches[$this->selected] ?? null)?->name();
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

    public function dismiss(): void
    {
        $this->matches = [];
        $this->setStyle(new Style(hidden: true));
    }

    /** @return list<string> */
    public function render(RenderContext $context): array
    {
        $columns = max(1, $context->getColumns());
        $limit = min(5, max(1, intdiv($this->getContext()?->getTerminalRows() ?? 24, 3)));
        $offset = max(0, $this->selected - $limit + 1);
        $visible = array_slice($this->matches, $offset, $limit);
        $nameWidth = 0;
        foreach ($this->matches as $command) {
            $nameWidth = max($nameWidth, AnsiUtils::visibleWidth($command->name()));
        }
        $nameWidth = min($nameWidth, max(1, intdiv($columns, 2) - 4));
        $lines = [];
        foreach ($visible as $index => $command) {
            $selected = $index + $offset === $this->selected;
            $name = AnsiUtils::truncateToWidth($command->name(), $nameWidth, '', true);
            $description = str_replace(["\r", "\n", "\t"], ' ', StringUtils::stripControlBytes($command->describe()));
            $line = $this->applyElement($selected ? 'selected' : 'name', ($selected ? '❯ ' : '  ') . $name)
                . '  ' . $this->applyElement('description', $description);
            $lines[] = AnsiUtils::truncateToWidth($line, $columns, '');
        }
        return $lines;
    }
}
