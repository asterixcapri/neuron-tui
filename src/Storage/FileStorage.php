<?php

declare(strict_types=1);

namespace NeuronTui\Storage;

use InvalidArgumentException;
use RuntimeException;

final readonly class FileStorage implements StorageInterface
{
    public function __construct(private string $root) {}

    public function read(string $namespace, string $key): ?string
    {
        $this->guardComponent($namespace, 'namespace');
        $this->guardComponent($key, 'key');

        if (!is_dir($this->root)) {
            return null;
        }

        $directory = $this->existingNamespaceDirectory($namespace);

        if ($directory === null) {
            return null;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $key;

        if (is_link($path)) {
            throw new RuntimeException('A storage key cannot be a symbolic link.');
        }

        if (!file_exists($path)) {
            return null;
        }

        if (!is_file($path)) {
            throw new RuntimeException('The stored value is not a regular file.');
        }

        $value = file_get_contents($path);

        if ($value === false) {
            throw new RuntimeException('The stored value could not be read.');
        }

        return $value;
    }

    public function write(
        string $namespace,
        string $key,
        string $value,
    ): void {
        $this->guardComponent($namespace, 'namespace');
        $this->guardComponent($key, 'key');

        $directory = $this->namespaceDirectory($namespace);
        $path = $directory . DIRECTORY_SEPARATOR . $key;

        if (is_link($path)) {
            throw new RuntimeException('A storage key cannot be a symbolic link.');
        }

        if (file_exists($path) && !is_file($path)) {
            throw new RuntimeException('The stored value is not a regular file.');
        }

        $temporary = tempnam($directory, '.neuron-tui-');

        if ($temporary === false) {
            throw new RuntimeException('A temporary storage file could not be created.');
        }

        try {
            $written = file_put_contents($temporary, $value, LOCK_EX);

            if ($written === false || $written !== strlen($value)) {
                throw new RuntimeException('The stored value could not be written.');
            }

            if (!rename($temporary, $path)) {
                throw new RuntimeException('The stored value could not be replaced.');
            }
        } finally {
            if (file_exists($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function namespaceDirectory(string $namespace): string
    {
        if (!is_dir($this->root) && !mkdir($this->root, 0777, true)) {
            throw new RuntimeException('The storage root could not be created.');
        }

        $root = realpath($this->root);

        if ($root === false) {
            throw new RuntimeException('The storage root could not be resolved.');
        }

        $directory = $root . DIRECTORY_SEPARATOR . $namespace;

        if (is_link($directory)) {
            throw new RuntimeException(
                'A storage namespace cannot be a symbolic link.',
            );
        }

        if (!is_dir($directory) && !mkdir($directory)) {
            throw new RuntimeException(
                'The storage namespace could not be created.',
            );
        }

        $resolved = realpath($directory);

        if ($resolved === false || dirname($resolved) !== $root) {
            throw new RuntimeException(
                'The storage namespace is outside the configured root.',
            );
        }

        return $resolved;
    }

    private function existingNamespaceDirectory(string $namespace): ?string
    {
        $root = realpath($this->root);

        if ($root === false) {
            throw new RuntimeException('The storage root could not be resolved.');
        }

        $directory = $root . DIRECTORY_SEPARATOR . $namespace;

        if (is_link($directory)) {
            throw new RuntimeException(
                'A storage namespace cannot be a symbolic link.',
            );
        }

        if (!file_exists($directory)) {
            return null;
        }

        $resolved = realpath($directory);

        if (
            $resolved === false
            || !is_dir($resolved)
            || dirname($resolved) !== $root
        ) {
            throw new RuntimeException(
                'The storage namespace is outside the configured root.',
            );
        }

        return $resolved;
    }

    private function guardComponent(string $component, string $name): void
    {
        if (
            $component === ''
            || $component === '.'
            || $component === '..'
            || str_contains($component, "\0")
            || str_contains($component, '/')
            || str_contains($component, '\\')
        ) {
            throw new InvalidArgumentException(
                sprintf('The storage %s is not a safe path component.', $name),
            );
        }
    }
}
