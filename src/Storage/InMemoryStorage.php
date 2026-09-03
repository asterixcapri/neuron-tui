<?php

declare(strict_types=1);

namespace NeuronTui\Storage;

use InvalidArgumentException;

final class InMemoryStorage extends AbstractStorage
{
    /**
     * @var array<string, array<string, array{
     *     data: array<array-key, mixed>,
     *     metadata: array<string, string>,
     * }>>
     */
    private array $documents = [];

    public function read(
        string $namespace,
        string $key,
    ): ?StoredDocument
    {
        $this->guardIdentifier($namespace, 'namespace');
        $this->guardIdentifier($key, 'key');
        $document = $this->documents[$namespace][$key] ?? null;

        return $document === null
            ? null
            : $this->storedDocument($key, $document);
    }

    /**
     * @param array<array-key, mixed> $data
     * @param array<string, string> $metadata
     */
    public function write(
        string $namespace,
        string $key,
        array $data,
        array $metadata = [],
    ): StoredDocument {
        $this->guardIdentifier($namespace, 'namespace');
        $this->guardIdentifier($key, 'key');
        $this->guardMetadata($metadata);

        $normalized = json_decode(
            json_encode($data, JSON_THROW_ON_ERROR),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        if (!is_array($normalized)) {
            throw new InvalidArgumentException(
                'Stored document data must be a JSON array or object.',
            );
        }

        $this->documents[$namespace][$key] = [
            'data' => $normalized,
            'metadata' => $metadata,
        ];

        return $this->storedDocument(
            $key,
            $this->documents[$namespace][$key],
        );
    }

    public function delete(string $namespace, string $key): void
    {
        $this->guardIdentifier($namespace, 'namespace');
        $this->guardIdentifier($key, 'key');

        unset($this->documents[$namespace][$key]);
    }

    /** @return list<StoredDocument> */
    public function entries(string $namespace): array
    {
        $this->guardIdentifier($namespace, 'namespace');
        $documents = $this->documents[$namespace] ?? [];

        $entries = [];

        foreach ($documents as $key => $document) {
            $entries[] = $this->storedDocument($key, $document);
        }

        return $entries;
    }

    /**
     * @param array{
     *     data: array<array-key, mixed>,
     *     metadata: array<string, string>,
     * } $document
     */
    private function storedDocument(
        string $key,
        array $document,
    ): StoredDocument {
        return new StoredDocument(
            $key,
            $document['data'],
            $document['metadata'],
        );
    }
}
