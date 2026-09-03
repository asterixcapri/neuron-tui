<?php

declare(strict_types=1);

namespace NeuronTui\Storage;

use RuntimeException;
use UnexpectedValueException;

final class FileStorage extends AbstractStorage
{
    private const string FILE_EXTENSION = '.json';

    public function __construct(private readonly string $root) {}

    protected function readDocument(
        string $namespace,
        string $key,
    ): ?StoredDocument
    {
        if (!is_dir($this->root)) {
            return null;
        }

        $directory = $this->existingNamespaceDirectory($namespace);

        if ($directory === null) {
            return null;
        }

        $path = $this->path($directory, $key);

        if (!file_exists($path) && !is_link($path)) {
            return null;
        }

        return $this->storedDocument($path, $key);
    }

    /**
     * @param array<array-key, mixed> $data
     * @param array<string, string> $metadata
     */
    protected function writeDocument(
        string $namespace,
        string $key,
        array $data,
        array $metadata,
    ): StoredDocument {
        $directory = $this->namespaceDirectory($namespace);
        $path = $this->path($directory, $key);

        if (is_link($path)) {
            throw new RuntimeException('A storage key cannot be a symbolic link.');
        }

        if (file_exists($path) && !is_file($path)) {
            throw new RuntimeException('The stored document is not a regular file.');
        }

        $encoded = json_encode(
            ['metadata' => $metadata, 'data' => $data],
            JSON_THROW_ON_ERROR,
        );
        $temporary = tempnam($directory, '.neuron-tui-');

        if ($temporary === false) {
            throw new RuntimeException('A temporary storage file could not be created.');
        }

        try {
            $written = file_put_contents($temporary, $encoded, LOCK_EX);

            if ($written === false || $written !== strlen($encoded)) {
                throw new RuntimeException('The stored document could not be written.');
            }

            if (!rename($temporary, $path)) {
                throw new RuntimeException('The stored document could not be replaced.');
            }

            return $this->storedDocument($path, $key);
        } finally {
            if (file_exists($temporary)) {
                unlink($temporary);
            }
        }
    }

    protected function deleteDocument(string $namespace, string $key): void
    {
        if (!is_dir($this->root)) {
            return;
        }

        $directory = $this->existingNamespaceDirectory($namespace);

        if ($directory === null) {
            return;
        }

        $path = $this->path($directory, $key);

        if (!file_exists($path) && !is_link($path)) {
            return;
        }

        if (is_link($path) || !is_file($path)) {
            throw new RuntimeException(
                'A stored document must be a regular file.',
            );
        }

        if (!unlink($path)) {
            throw new RuntimeException(
                'The stored document could not be deleted.',
            );
        }
    }

    /** @return list<StoredDocument> */
    protected function readEntries(string $namespace): iterable
    {
        if (!is_dir($this->root)) {
            return [];
        }

        $directory = $this->existingNamespaceDirectory($namespace);

        if ($directory === null) {
            return [];
        }

        $paths = glob(
            $directory . DIRECTORY_SEPARATOR . '*' . self::FILE_EXTENSION,
        ) ?: [];
        $entries = [];

        foreach ($paths as $path) {
            $key = substr(
                basename($path),
                0,
                -strlen(self::FILE_EXTENSION),
            );
            $entries[] = $this->storedDocument($path, $key);
        }

        return $entries;
    }

    private function path(string $directory, string $key): string
    {
        return $directory
            . DIRECTORY_SEPARATOR
            . $key
            . self::FILE_EXTENSION;
    }

    private function storedDocument(
        string $path,
        string $key,
    ): StoredDocument {
        if (is_link($path)) {
            throw new RuntimeException('A storage key cannot be a symbolic link.');
        }

        if (!is_file($path)) {
            throw new RuntimeException('The stored document is not a regular file.');
        }

        $encoded = file_get_contents($path);

        if ($encoded === false) {
            throw new RuntimeException('The stored document could not be read.');
        }

        $document = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);

        if (
            !is_array($document)
            || !isset($document['metadata'], $document['data'])
            || !is_array($document['metadata'])
            || !is_array($document['data'])
        ) {
            throw new UnexpectedValueException(
                'A stored document must contain metadata and data.',
            );
        }

        $metadata = [];

        foreach ($document['metadata'] as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                throw new UnexpectedValueException(
                    'Stored document metadata must contain string pairs.',
                );
            }

            $metadata[$name] = $value;
        }

        return new StoredDocument(
            $key,
            $document['data'],
            $metadata,
        );
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
}
