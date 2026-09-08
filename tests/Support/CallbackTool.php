<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Support;

use Closure;
use NeuronAI\Tools\Tool;

final class CallbackTool extends Tool
{
    /** @param Closure(): string $callback */
    public function __construct(string $name, private readonly Closure $callback)
    {
        $this->name = $name;
    }

    public function __invoke(): string
    {
        return ($this->callback)();
    }
}
