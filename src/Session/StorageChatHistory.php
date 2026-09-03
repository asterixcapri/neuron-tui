<?php

declare(strict_types=1);

namespace NeuronTui\Session;

use DateTimeImmutable;
use DateTimeZone;
use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\History\HistoryTrimmer;
use NeuronAI\Chat\History\HistoryTrimmerInterface;
use NeuronAI\Chat\Messages\Message;
use NeuronTui\Storage\StorageInterface;
use NeuronTui\Storage\StoredDocument;
use UnexpectedValueException;

use function array_is_list;
use function is_array;

/**
 * Neuron AI History persisted as one JSON storage document.
 *
 * @internal Sessions owns this bridge between its storage and the Agent.
 */
final class StorageChatHistory extends AbstractChatHistory
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly string $namespace,
        private readonly string $key,
        int $contextWindow = 50000,
        HistoryTrimmerInterface $trimmer = new HistoryTrimmer(),
        ?StoredDocument $document = null,
    ) {
        parent::__construct($contextWindow, $trimmer);

        $this->load($document);
    }

    /**
     * @param list<Message> $messages
     */
    protected function setMessages(array $messages): void
    {
        $this->persist($messages);
    }

    protected function clear(): void
    {
        $this->persist([]);
    }

    private function load(?StoredDocument $document): void
    {
        $document ??= $this->storage->read($this->namespace, $this->key);

        if ($document === null) {
            return;
        }

        $messages = $document->data;

        if (!array_is_list($messages)) {
            throw new UnexpectedValueException(
                'A stored Chat History must be a JSON array.',
            );
        }

        foreach ($messages as $message) {
            if (!is_array($message)) {
                throw new UnexpectedValueException(
                    'Every stored Chat History entry must be a JSON object.',
                );
            }
        }

        /** @var list<array<string, mixed>> $messages */
        $this->history = $this->deserializeMessages($messages);
    }

    /** @param list<Message> $messages */
    private function persist(array $messages): void
    {
        $lastUsedAt = new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC'),
        );

        $this->storage->write(
            $this->namespace,
            $this->key,
            $messages,
            ['lastUsedAt' => $lastUsedAt->format('Y-m-d\TH:i:s.uP')],
        );
    }
}
