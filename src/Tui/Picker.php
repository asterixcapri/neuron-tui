<?php

declare(strict_types=1);

namespace NeuronCli\Tui;

use Closure;
use NeuronCli\Conversation\ChoiceOption;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * The list a person moves through while the TUI is in the Picker.
 *
 * Arrow navigation and complete option blocks belong to the internal list;
 * this object adds the surrounding lower panel and the filtering.
 * Whatever the options stand for is the caller's business: the picker takes
 * ChoiceOptions and hands back the key of the one a person chose. Whoever
 * opens the picker decides what focus and the composer do meanwhile.
 *
 * @internal
 */
final class Picker
{
    /** The choosing state may occupy at most two fifths of the terminal. */
    private const int PANEL_HEIGHT_PERCENT = 40;

    /** Top border and padding applied by the Picker container style. */
    private const int PANEL_DECORATION_ROWS = 2;

    private const string INSTRUCTIONS =
        '↑↓ move · Enter chooses · Escape cancels';

    /**
     * What a handle starts with, so that it is a name of the picker's own
     * rather than a bare number a caller might read something into.
     */
    private const string HANDLE_PREFIX = 'option-';

    private readonly ContainerWidget $widget;

    private readonly TextWidget $heading;

    private readonly TextWidget $instructions;

    private readonly TextWidget $search;

    private readonly PickerList $list;

    private ?PickerDescription $description = null;

    /**
     * The keys on offer, each under the handle its line carries.
     *
     * @var array<string, string>
     */
    private array $offered = [];

    /**
     * Every line of the open picker, filtered or not, in the order the caller
     * listed them.
     *
     * @var list<PickerListItem>
     */
    private array $lines = [];

    /** @var list<PickerListItem> */
    private array $shown = [];

    private string $title = '';

    private string $filter = '';

    private bool $searchable = false;

    private bool $open = false;

    /**
     * @param Closure(string): void $chosen
     * @param Closure(): void $abandoned
     * @param Closure(): int $terminalRows
     */
    public function __construct(
        private readonly Closure $chosen,
        private readonly Closure $abandoned,
        private readonly Closure $terminalRows,
    ) {
        $this->widget = new ContainerWidget();
        $this->widget->addStyleClass('picker');
        $this->heading = new TextWidget('');
        $this->heading->addStyleClass('picker-heading');
        $this->instructions = new TextWidget('');
        $this->instructions->addStyleClass('picker-instructions');
        $this->search = new TextWidget('');
        $this->search->addStyleClass('picker-search');
        $this->list = new PickerList(
            $this->choose(...),
            $this->abandon(...),
            $this->type(...),
            $this->positionChanged(...),
            $this->availableListRows(...),
        );
        $this->list->addStyleClass('picker-list');
    }

    public function widget(): ContainerWidget
    {
        return $this->widget;
    }

    /**
     * The widget the keys belong to while the picker is open.
     */
    public function focusable(): PickerList
    {
        return $this->list;
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    /**
     * Shows the options, in the order they were given, under the title that
     * says what is being chosen.
     *
     * @param non-empty-list<ChoiceOption> $options
     */
    public function open(
        string $title,
        array $options,
        ?string $description = null,
    ): void
    {
        $this->title = DisplayableText::singleLine($title);
        $this->filter = '';
        $this->searchable = count($options) >= 6;
        $this->description = null;
        $this->offered = [];
        $this->lines = [];

        $place = 0;

        foreach ($options as $option) {
            $handle = self::HANDLE_PREFIX . $place++;
            $this->offered[$handle] = $option->key;
            $this->lines[] = new PickerListItem(
                $handle,
                $option->label,
                $option->detail,
            );
        }

        $this->shown = $this->lines;
        $this->list->setQuery('');
        $this->list->setItems($this->shown);
        $this->instructions->setText(self::INSTRUCTIONS);
        $this->widget->clear();
        $this->widget->add($this->heading);

        if ($description !== null) {
            $this->description = new PickerDescription($description);
            $this->description->addStyleClass('picker-description');
            $this->widget->add($this->description);
        }

        if ($this->searchable) {
            $this->updateSearch();
            $this->widget->add($this->search);
        }

        $this->widget->add($this->list);
        $this->widget->add($this->instructions);
        $this->open = true;
    }

    /**
     * Takes the list away, leaving nothing of the choice on screen and no key
     * held past the moment a person could still choose it.
     */
    public function close(): void
    {
        $this->widget->clear();
        $this->offered = [];
        $this->lines = [];
        $this->shown = [];
        $this->filter = '';
        $this->searchable = false;
        $this->description = null;
        $this->list->reset();
        $this->open = false;
    }

    /**
     * Narrows the list by what is being typed.
     *
     * Anything that is not text — an arrow, Enter, Escape — is left to the
     * list, which is the only thing that knows what to do with it.
     */
    private function type(string $data): bool
    {
        if (!$this->searchable) {
            return false;
        }

        if ($data === "\x7f" || $data === "\x08") {
            if ($this->filter !== '') {
                $this->filterBy(mb_substr($this->filter, 0, -1));
            }

            return true;
        }

        if ($data === '' || preg_match('/[\x00-\x1f\x7f]/', $data) === 1) {
            return false;
        }

        $this->filterBy($this->filter . $data);

        return true;
    }

    /**
     * Narrows to the lines containing what was typed in their complete label
     * or detail. array_filter preserves the command's supplied order.
     */
    private function filterBy(string $filter): void
    {
        $this->filter = $filter;
        $matching = array_values(array_filter(
            $this->lines,
            static fn (PickerListItem $line): bool => self::contains(
                $line->label,
                $filter,
            ) || (
                $line->detail !== null
                && self::contains($line->detail, $filter)
            ),
        ));

        $this->shown = $matching;
        $this->list->setQuery($filter);
        $this->list->setItems($matching);
        $this->updateSearch();
    }

    private function updateSearch(): void
    {
        $this->search->setText(
            'Search: ' . DisplayableText::safe($this->filter),
        );
    }

    /**
     * Rows the list may use inside a panel capped at 40% of the terminal.
     *
     * A compact choice consumes only what its complete blocks need. A long
     * choice may grow to this budget, while the remaining terminal stays
     * available to the History above it.
     */
    private function availableListRows(int $columns): int
    {
        $childCount = 3; // Heading, list and instructions.
        $fixedRows = $this->textHeight($this->heading, $columns)
            + $this->textHeight($this->instructions, $columns);

        if ($this->description instanceof PickerDescription) {
            ++$childCount;
            $fixedRows += $this->description->heightForWidth($columns);
        }

        if ($this->searchable) {
            ++$childCount;
            $fixedRows += $this->textHeight($this->search, $columns);
        }

        $panelRows = intdiv(
            max(1, ($this->terminalRows)()) * self::PANEL_HEIGHT_PERCENT,
            100,
        );

        // The container has one row of gap between adjacent children.
        return max(
            1,
            $panelRows
                - self::PANEL_DECORATION_ROWS
                - $fixedRows
                - $childCount
                + 1,
        );
    }

    /** Matches the wrapping performed by Symfony's TextWidget renderer. */
    private function textHeight(TextWidget $text, int $columns): int
    {
        return count($text->render(new RenderContext(
            max(1, $columns),
            1,
        )));
    }

    private static function contains(string $text, string $filter): bool
    {
        return mb_stripos(DisplayableText::singleLine($text), $filter) !== false;
    }

    private function positionChanged(int $position, int $total): void
    {
        $this->heading->setText(sprintf(
            '%s (%d of %d)',
            $this->title,
            $position,
            $total,
        ));
    }

    private function choose(string $handle): void
    {
        $chosen = $this->offered[$handle] ?? null;

        if ($chosen === null) {
            // A value the picker did not put in the list names no option, so
            // there is nothing to choose and the list stays where it is.
            return;
        }

        ($this->chosen)($chosen);
    }

    private function abandon(): void
    {
        ($this->abandoned)();
    }
}
