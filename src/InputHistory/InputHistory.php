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

    /** The entry currently recalled into the composer, when there is one. */
    private ?int $position = null;

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
        $this->leave();

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

    /**
     * Moves toward older inputs, entering navigation at the newest one.
     */
    public function older(): ?string
    {
        if ($this->entries === []) {
            return null;
        }

        $this->position = $this->position === null
            ? array_key_last($this->entries)
            : max(0, $this->position - 1);

        return $this->entries[$this->position];
    }

    /**
     * Moves toward newer inputs, returning to an empty draft past the end.
     */
    public function newer(): ?string
    {
        if ($this->position === null) {
            return null;
        }

        if ($this->position === array_key_last($this->entries)) {
            $this->leave();

            return '';
        }

        ++$this->position;

        return $this->entries[$this->position];
    }

    public function isNavigating(): bool
    {
        return $this->position !== null;
    }

    /** The composer draft has become independent from its stored source. */
    public function leave(): void
    {
        $this->position = null;
    }
}
