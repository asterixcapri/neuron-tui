<?php

declare(strict_types=1);

namespace NeuronTui\Storage;

/**
 * One JSON document read through Storage under its logical key.
 */
final readonly class StoredDocument
{
    /**
     * @param array<array-key, mixed> $data
     * @param array<string, string> $metadata
     */
    public function __construct(
        public string $key,
        public array $data,
        public array $metadata,
    ) {}

    /**
     * Number of bytes in this document's runtime JSON representation.
     */
    public function size(): int
    {
        return strlen(json_encode($this->data, JSON_THROW_ON_ERROR));
    }
}
