<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Tools;

use Closure;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolCall;

/** Executable tool fixture; conversation records are created separately. */
final class CallbackTool extends Tool
{
    /** @var Closure(): string */
    private Closure $callback;

    /** @var array<string, mixed> */
    private array $callInputs = [];

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->callback = static fn (): string => '';
    }

    /** @param callable(): string $callback */
    public function setCallable(callable $callback): self
    {
        $this->callback = Closure::fromCallable($callback);
        return $this;
    }

    public function __invoke(): string
    {
        return ($this->callback)();
    }

    public function setCallId(?string $callId): self
    {
        parent::setCallId($callId);
        return $this;
    }

    /** @param array<string, mixed>|null $inputs */
    public function setInputs(?array $inputs): self
    {
        $this->callInputs = $inputs ?? [];
        parent::setInputs($inputs);
        return $this;
    }

    public function call(): ToolCall
    {
        return new ToolCall(name: $this->name, callId: $this->callId, inputs: $this->callInputs, description: $this->description);
    }
}
