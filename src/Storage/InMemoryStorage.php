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

    protected function readDocument(
        string $namespace,
        string $key,
    ): ?StoredDocument
    {
        $document = $this->documents[$namespace][$key] ?? null;

        return $document === null
            ? null
            : $this->storedDocument($key, $document);
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

    protected function deleteDocument(string $namespace, string $key): void
    {
        unset($this->documents[$namespace][$key]);
    }

    /** @return list<StoredDocument> */
    protected function readEntries(string $namespace): iterable
    {
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
