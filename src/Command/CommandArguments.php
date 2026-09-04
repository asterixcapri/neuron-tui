<?php

declare(strict_types=1);

namespace NeuronTui\Command;

/** Raw text supplied by the Adapter; dispatch preserves every character. */
final readonly class CommandArguments
{
    public function __construct(public string $text = '')
    {
    }
}
