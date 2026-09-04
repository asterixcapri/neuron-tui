<?php

declare(strict_types=1);

namespace NeuronTui\Command;

use InvalidArgumentException;

/** A choice to present before invoking the target Command again. */
final readonly class SelectionRequest
{
    /** @var non-empty-list<SelectionOption> */
    public array $options;

    /** @param array<array-key, SelectionOption> $options */
    public function __construct(
        public string $command,
        public string $prompt,
        array $options,
        public ?string $description = null,
    ) {
        if ($options === [] || !array_is_list($options)) {
            throw new InvalidArgumentException('A selection must offer an ordered non-empty list of options.');
        }

        $this->options = $options;
    }
}
