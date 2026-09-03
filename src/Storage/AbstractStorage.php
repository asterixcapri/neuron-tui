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
    final public function create(
        string $namespace,
        array $data,
        array $metadata = [],
    ): StoredDocument {
        $this->guardIdentifier($namespace, 'namespace');
        $this->guardMetadata($metadata);

        $document = $this->createDocument(
            $namespace,
            $data,
            $metadata,
        );
        $this->guardIdentifier($document->key, 'key');

        return $document;
    }

    final public function read(
        string $namespace,
        string $key,
    ): ?StoredDocument {
        $this->guardIdentifier($namespace, 'namespace');
        $this->guardIdentifier($key, 'key');

        return $this->readDocument($namespace, $key);
    }

    /**
     * @param array<array-key, mixed> $data
     * @param array<string, string> $metadata
     */
    final public function write(
        string $namespace,
        string $key,
        array $data,
        array $metadata = [],
    ): StoredDocument {
        $this->guardIdentifier($namespace, 'namespace');
        $this->guardIdentifier($key, 'key');
        $this->guardMetadata($metadata);

        return $this->writeDocument(
            $namespace,
            $key,
            $data,
            $metadata,
        );
    }

    final public function delete(string $namespace, string $key): void
    {
        $this->guardIdentifier($namespace, 'namespace');
        $this->guardIdentifier($key, 'key');

        $this->deleteDocument($namespace, $key);
    }

    /** @return iterable<StoredDocument> */
    final public function entries(string $namespace): iterable
    {
        $this->guardIdentifier($namespace, 'namespace');

        return $this->guardEntries($this->readEntries($namespace));
    }

    /**
     * Adapters may replace this with a native atomic creation operation.
     *
     * @param array<array-key, mixed> $data
     * @param array<string, string> $metadata
     */
    protected function createDocument(
        string $namespace,
        array $data,
        array $metadata,
    ): StoredDocument {

        do {
            $key = $this->newKey();
        } while ($this->readDocument($namespace, $key) !== null);

        return $this->writeDocument($namespace, $key, $data, $metadata);
    }

    abstract protected function readDocument(
        string $namespace,
        string $key,
    ): ?StoredDocument;

    /**
     * @param array<array-key, mixed> $data
     * @param array<string, string> $metadata
     */
    abstract protected function writeDocument(
        string $namespace,
        string $key,
        array $data,
        array $metadata,
    ): StoredDocument;

    abstract protected function deleteDocument(
        string $namespace,
        string $key,
    ): void;

    /** @return iterable<StoredDocument> */
    abstract protected function readEntries(string $namespace): iterable;

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

    /**
     * @param iterable<StoredDocument> $entries
     *
     * @return iterable<StoredDocument>
     */
    private function guardEntries(iterable $entries): iterable
    {
        foreach ($entries as $document) {
            $this->guardIdentifier($document->key, 'key');

            yield $document;
        }
    }
}
