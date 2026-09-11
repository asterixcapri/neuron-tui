<?php

declare(strict_types=1);

namespace NeuronTui\View\Widget;

/** @internal */
final readonly class PaintedAnchor
{
    public function __construct(
        public string $text,
        public int $offset,
        public int $row,
    ) {
    }
}
