<?php

declare(strict_types=1);

namespace NeuronTui\Storage;

interface StorageInterface
{
    public function read(string $namespace, string $key): ?string;

    public function write(
        string $namespace,
        string $key,
        string $value,
    ): void;
}
