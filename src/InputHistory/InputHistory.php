<?php

declare(strict_types=1);

namespace NeuronTui\InputHistory;

use NeuronTui\Storage\StorageInterface;
use UnexpectedValueException;

/**
 * Composer inputs persisted independently of Sessions and Agent History.
 *
 * @internal The Conversation Runtime owns this TUI state.
 */
final class InputHistory
{
    private const string NAMESPACE = 'input-history';

    private const string KEY = 'entries';

    /** @var list<string> */
    private array $entries;

    public function __construct(private readonly StorageInterface $storage)
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
        $this->entries = $entries;
    }

    public function record(string $input): void
    {
        $this->entries[] = $input;
        $this->storage->write(
            self::NAMESPACE,
            self::KEY,
            $this->entries,
        );
    }

    public function newest(): ?string
    {
        if ($this->entries === []) {
            return null;
        }

        return $this->entries[array_key_last($this->entries)];
    }
}
