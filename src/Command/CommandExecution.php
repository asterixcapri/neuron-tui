<?php

declare(strict_types=1);

namespace NeuronTui\Command;

use Throwable;

/** The technical outcome of dispatch, independent from a Command's effects. */
final readonly class CommandExecution
{
    private function __construct(
        public string $identifier,
        public string $status,
        public ?Throwable $exception = null,
    ) {
    }

    public static function completed(string $identifier): self
    {
        return new self($identifier, 'completed');
    }

    public static function unknown(string $identifier): self
    {
        return new self($identifier, 'unknown');
    }

    public static function failed(string $identifier, Throwable $exception): self
    {
        return new self($identifier, 'failed', $exception);
    }
}
