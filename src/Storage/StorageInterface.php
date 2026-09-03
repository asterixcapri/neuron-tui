<?php

declare(strict_types=1);

namespace NeuronTui\Storage;

/**
 * Persists JSON documents under namespaced logical keys.
 *
 * Adapters own JSON encoding, physical naming and discovery. Caller-owned
 * metadata uses lowercase string names and string values for portability.
 */
interface StorageInterface
{
    /**
     * Creates a document under a new adapter-generated opaque key.
     *
     * @param array<array-key, mixed> $data
     * @param array<string, string> $metadata
     */
    public function create(
        string $namespace,
        array $data,
        array $metadata = [],
    ): StoredDocument;

    /** A missing document returns null without creating storage state. */
    public function read(
        string $namespace,
        string $key,
    ): ?StoredDocument;

    /**
     * @param array<array-key, mixed> $data
     * @param array<string, string> $metadata
     */
    public function write(
        string $namespace,
        string $key,
        array $data,
        array $metadata = [],
    ): StoredDocument;

    /** Removes a document if it exists. */
    public function delete(string $namespace, string $key): void;

    /**
     * The namespace's documents, in adapter-defined order.
     *
     * @return iterable<StoredDocument>
     */
    public function entries(string $namespace): iterable;
}
