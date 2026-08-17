<?php

declare(strict_types=1);

namespace NeuronCli\Session;

use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\FileChatHistory;

/**
 * Keeps the Sessions of an Agent in a directory, one file per Session.
 *
 * This is what a Host Application that configures nothing gets. The files and
 * their format belong to Neuron AI's `FileChatHistory`; the only decision
 * taken here is where they live and how a key is minted.
 */
final readonly class FileSessionStore implements SessionStore
{
    /**
     * Relative to the working directory of the Host Application, so Sessions
     * follow the project rather than the machine.
     */
    private const string DEFAULT_DIRECTORY = '.neuron/sessions';

    private string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? (getcwd() ?: '.')
            . '/' . self::DEFAULT_DIRECTORY;
    }

    public function open(?string $key = null): ChatHistoryInterface
    {
        return new FileChatHistory(
            $this->directory,
            $key ?? $this->mintKey(),
        );
    }

    /**
     * A key is only ever a name for one conversation, so it has to be new and
     * usable as a file name, and nothing more.
     */
    private function mintKey(): string
    {
        return bin2hex(random_bytes(8));
    }
}
