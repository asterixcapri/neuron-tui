<?php

declare(strict_types=1);

namespace NeuronTui\Session;

use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\History\HistoryTrimmer;
use NeuronAI\Chat\History\HistoryTrimmerInterface;
use NeuronAI\Chat\Messages\Message;
use NeuronTui\Storage\StorageInterface;
use UnexpectedValueException;

use function array_is_list;
use function is_array;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Neuron AI History persisted as one opaque storage value.
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
        private readonly ?Sessions $sessions = null,
        private readonly ?string $sessionKey = null,
    ) {
        parent::__construct($contextWindow, $trimmer);

        $this->load();
    }

    /**
     * @param list<Message> $messages
     */
    protected function setMessages(array $messages): void
    {
        $this->write(json_encode($messages, JSON_THROW_ON_ERROR));
    }

    protected function clear(): void
    {
        $this->write('[]');
    }

    private function write(string $value): void
    {
        $this->storage->write($this->namespace, $this->key, $value);

        if ($this->sessions !== null && $this->sessionKey !== null) {
            $this->sessions->recordUse($this->sessionKey);
        }
    }

    private function load(): void
    {
        $value = $this->storage->read($this->namespace, $this->key);

        if ($value === null) {
            return;
        }

        $messages = json_decode($value, true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($messages) || !array_is_list($messages)) {
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
}
