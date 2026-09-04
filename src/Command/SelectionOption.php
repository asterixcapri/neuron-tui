<?php

declare(strict_types=1);

namespace NeuronTui\Command;

/** A stable value and the text an Adapter may present for it. */
final readonly class SelectionOption
{
    public function __construct(
        public string $value,
        public string $label,
        public ?string $description = null,
    ) {
    }
}
