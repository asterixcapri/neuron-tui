<?php

declare(strict_types=1);

namespace NeuronCli\Tui;

use Closure;
use NeuronCli\Conversation\ChoiceOption;
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

    private readonly PickerList $list;

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
     * @var list<array{value: string, label: string, detail: string|null}>
     */
    private array $lines = [];

    /** @var list<array{value: string, label: string, detail: string|null}> */
    private array $shown = [];

    private string $title = '';

    private string $filter = '';

    private bool $open = false;

    /**
     * @param Closure(string): void $chosen
     * @param Closure(): void $abandoned
     */
    public function __construct(
        private readonly Closure $chosen,
        private readonly Closure $abandoned,
    ) {
        $this->widget = new ContainerWidget();
        $this->widget->addStyleClass('picker');
        $this->heading = new TextWidget('');
        $this->heading->addStyleClass('picker-heading');
        $this->instructions = new TextWidget('');
        $this->instructions->addStyleClass('picker-instructions');
        $this->list = new PickerList(
            $this->choose(...),
            $this->abandon(...),
            $this->type(...),
            $this->positionChanged(...),
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
        $this->title = preg_replace(
            '/\s+/u',
            ' ',
            trim(DisplayableText::safe($title)),
        ) ?? '';
        $this->filter = '';
        $this->offered = [];
        $this->lines = [];

        $place = 0;

        foreach ($options as $option) {
            $handle = self::HANDLE_PREFIX . $place++;
            $this->offered[$handle] = $option->key;
            $this->lines[] = [
                'value' => $handle,
                'label' => $option->label,
                'detail' => $option->detail,
            ];
        }

        $this->shown = $this->lines;
        $this->list->setItems($this->shown);
        $this->instructions->setText(self::INSTRUCTIONS);
        $this->widget->clear();
        $this->widget->add($this->heading);

        if ($description !== null) {
            $explanation = new PickerDescription($description);
            $explanation->addStyleClass('picker-description');
            $this->widget->add($explanation);
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
        if ($data === "\x7f" || $data === "\x08") {
            $this->filterBy(mb_substr($this->filter, 0, -1));

            return true;
        }

        if ($data === '' || preg_match('/[\x00-\x1f\x7f]/', $data) === 1) {
            return false;
        }

        $this->filterBy($this->filter . $data);

        return true;
    }

    /**
     * Narrows to the lines whose label starts with what was typed.
     *
     * The list narrows on the value of a line, which is a handle of the
     * picker's own, so the narrowing happens here against the label. A
     * keystroke that narrows nothing leaves the list alone, so that the line
     * a person moved to stays the one under the arrow.
     */
    private function filterBy(string $filter): void
    {
        $this->filter = $filter;
        $matching = array_values(array_filter(
            $this->lines,
            static fn (array $line): bool => str_starts_with(
                strtolower($line['label']),
                strtolower($filter),
            ),
        ));

        if ($matching !== $this->shown) {
            $this->shown = $matching;
            $this->list->setItems($matching);
        }
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
