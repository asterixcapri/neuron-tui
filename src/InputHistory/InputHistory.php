<?php

declare(strict_types=1);

namespace NeuronTui\InputHistory;

use NeuronTui\Storage\StorageInterface;
use UnexpectedValueException;

/**
 * Submitted inputs persisted independently of Sessions and Agent History.
 */
final class InputHistory
{
    private const string NAMESPACE = 'input-history';

    private const string KEY = 'entries';

    /** @var list<string> */
    private array $entries;

    public function __construct(private readonly StorageInterface $storage)
    {
        $this->entries = $this->loadEntries();
    }

    /** @return list<string> */
    private function loadEntries(): array
    {
        $document = $this->storage->read(self::NAMESPACE, self::KEY);
        $entries = $document->data ?? [];

        if (!array_is_list($entries)) {
            throw new UnexpectedValueException(
                'Stored Input history must be a JSON array.',
            );
        }

        foreach ($entries as $entry) {
            if (!is_string($entry)) {
                throw new UnexpectedValueException(
                    'Every stored Input history entry must be text.',
                );
            }
        }

        /** @var list<string> $entries */
        return $entries;
    }

    public function record(string $input): void
    {
        if (
            trim($input) === ''
            || ($this->entries !== [] && end($this->entries) === $input)
        ) {
            return;
        }

        $this->entries[] = $input;
        $this->storage->write(
            self::NAMESPACE,
            self::KEY,
            $this->entries,
        );
    }

    /** @return list<string> Submitted inputs, oldest first. */
    public function entries(): array
    {
        return $this->entries;
    }
}
