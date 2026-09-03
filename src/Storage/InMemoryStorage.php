<?php

declare(strict_types=1);

namespace NeuronTui\Storage;

final class InMemoryStorage implements StorageInterface
{
    /**
     * @var array<string, array<string, string>>
     */
    private array $values = [];

    public function read(string $namespace, string $key): ?string
    {
        return $this->values[$namespace][$key] ?? null;
    }

    public function write(
        string $namespace,
        string $key,
        string $value,
    ): void {
        $this->values[$namespace][$key] = $value;
    }
}
