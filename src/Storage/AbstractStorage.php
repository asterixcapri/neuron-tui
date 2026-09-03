<?php

declare(strict_types=1);

namespace NeuronTui\Storage;

use InvalidArgumentException;

/**
 * Common portable identifier, metadata and key-generation behaviour.
 */
abstract class AbstractStorage implements StorageInterface
{
    /**
     * @param array<array-key, mixed> $data
     * @param array<string, string> $metadata
     */
    public function create(
        string $namespace,
        array $data,
        array $metadata = [],
    ): StoredDocument {
        $this->guardIdentifier($namespace, 'namespace');

        do {
            $key = $this->newKey();
        } while ($this->read($namespace, $key) !== null);

        return $this->write($namespace, $key, $data, $metadata);
    }

    final protected function guardIdentifier(
        string $identifier,
        string $name,
    ): void {
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/D', $identifier) !== 1) {
            throw new InvalidArgumentException(
                sprintf('The storage %s is not a safe identifier.', $name),
            );
        }
    }

    final protected function newKey(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** @param array<array-key, mixed> $metadata */
    final protected function guardMetadata(array $metadata): void
    {
        foreach ($metadata as $name => $value) {
            if (
                !is_string($name)
                || !is_string($value)
                || preg_match('/^[a-z0-9][a-z0-9-]*$/D', $name) !== 1
            ) {
                throw new InvalidArgumentException(
                    'Storage metadata must contain lowercase string pairs.',
                );
            }
        }
    }
}
