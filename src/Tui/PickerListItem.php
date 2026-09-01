<?php

declare(strict_types=1);

namespace NeuronTui\Tui;

/**
 * One presentation-ready entry passed from Picker to PickerList.
 *
 * @internal
 */
final readonly class PickerListItem
{
    public function __construct(
        public string $value,
        public string $label,
        public ?string $detail,
    ) {
    }
}
