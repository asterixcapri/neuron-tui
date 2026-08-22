<?php

declare(strict_types=1);

namespace NeuronCli\Tui;

use Closure;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Ansi\TextWrapper;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;

/**
 * A choice list measured in complete, independently wrapped option blocks.
 *
 * Symfony's SelectListWidget owns one terminal row per item. A Picker option
 * may own several rows, so putting these values through that widget would let
 * its viewport separate a label from its detail. This small widget keeps the
 * same keyboard contract while treating the whole option as the unit of
 * navigation and scrolling.
 *
 * @internal
 */
final class PickerList extends AbstractWidget implements FocusableInterface
{
    use FocusableTrait;
    use KeybindingsTrait;

    private const int MAX_VISIBLE_BLOCKS = 8;

    private const int MAX_TEXT_LINES = 2;

    /**
     * @var list<array{value: string, label: string, detail: string|null}>
     */
    private array $items = [];

    private int $selectedIndex = 0;

    private string $query = '';

    /**
     * @param Closure(string): void $selected
     * @param Closure(): void $cancelled
     * @param Closure(string): bool $typed
     * @param Closure(int, int): void $positionChanged
     */
    public function __construct(
        private readonly Closure $selected,
        private readonly Closure $cancelled,
        private readonly Closure $typed,
        private readonly Closure $positionChanged,
    ) {
    }

    /**
     * @param list<array{value: string, label: string, detail: string|null}> $items
     */
    public function setItems(array $items): void
    {
        $this->items = $items;
        $this->selectedIndex = 0;
        ($this->positionChanged)(
            $items === [] ? 0 : 1,
            count($items),
        );
        $this->invalidate();
    }

    public function setQuery(string $query): void
    {
        $this->query = DisplayableText::safe($query);
    }

    public function handleInput(string $data): void
    {
        if (($this->typed)($data)) {
            return;
        }

        $keybindings = $this->getKeybindings();

        if ($this->items !== []) {
            if ($keybindings->matches($data, 'select_up')) {
                $this->selectedIndex = $this->selectedIndex === 0
                    ? count($this->items) - 1
                    : $this->selectedIndex - 1;
                $this->announcePosition();
                $this->invalidate();

                return;
            }

            if ($keybindings->matches($data, 'select_down')) {
                $this->selectedIndex = $this->selectedIndex
                    === count($this->items) - 1
                    ? 0
                    : $this->selectedIndex + 1;
                $this->announcePosition();
                $this->invalidate();

                return;
            }

            if ($keybindings->matches($data, 'select_confirm')) {
                ($this->selected)($this->items[$this->selectedIndex]['value']);

                return;
            }
        }

        if ($keybindings->matches($data, 'select_cancel')) {
            ($this->cancelled)();
        }
    }

    /** @return string[] */
    public function render(RenderContext $context): array
    {
        if ($this->items === []) {
            return [$this->applyElement(
                'no-match',
                sprintf('  No options match "%s"', $this->query),
            )];
        }

        $start = max(0, min(
            $this->selectedIndex - intdiv(self::MAX_VISIBLE_BLOCKS, 2),
            count($this->items) - self::MAX_VISIBLE_BLOCKS,
        ));
        $end = min($start + self::MAX_VISIBLE_BLOCKS, count($this->items));
        $lines = [];

        for ($index = $start; $index < $end; ++$index) {
            if ($lines !== []) {
                $lines[] = '';
            }

            array_push(
                $lines,
                ...$this->renderBlock(
                    $this->items[$index],
                    $index === $this->selectedIndex,
                    $context->getColumns(),
                ),
            );
        }

        return $lines;
    }

    /**
     * @return array<string, string[]>
     */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'select_up' => [Key::UP],
            'select_down' => [Key::DOWN],
            'select_confirm' => [Key::ENTER],
            'select_cancel' => [Key::ESCAPE, 'ctrl+c'],
        ];
    }

    /**
     * @param array{value: string, label: string, detail: string|null} $item
     *
     * @return non-empty-list<string>
     */
    private function renderBlock(
        array $item,
        bool $selected,
        int $columns,
    ): array {
        $textWidth = max(1, $columns - 2);
        $labelLines = self::wrapped($item['label'], $textWidth);
        $lines = [];

        foreach ($labelLines as $line => $text) {
            $prefix = $line === 0 && $selected ? '→ ' : '  ';
            $lines[] = $prefix . ($selected
                ? $this->applyElement('selected', $text)
                : $text);
        }

        if ($item['detail'] !== null) {
            foreach (self::wrapped($item['detail'], $textWidth) as $text) {
                $lines[] = '  ' . $this->applyElement('detail', $text);
            }
        }

        return $lines;
    }

    /** @return non-empty-list<string> */
    private static function wrapped(string $text, int $width): array
    {
        $normalized = preg_replace(
            '/\s+/u',
            ' ',
            trim(DisplayableText::safe($text)),
        ) ?? '';
        $lines = TextWrapper::wrapTextWithAnsi($normalized, $width);

        if (count($lines) <= self::MAX_TEXT_LINES) {
            /** @var non-empty-list<string> $lines */
            return $lines;
        }

        return [
            $lines[0],
            AnsiUtils::truncateToWidth($lines[1] . '…', $width, '…'),
        ];
    }

    private function announcePosition(): void
    {
        ($this->positionChanged)(
            $this->selectedIndex + 1,
            count($this->items),
        );
    }
}
