<?php

declare(strict_types=1);

namespace NeuronCli\Session;

use DateTimeImmutable;
use DateTimeZone;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\FileChatHistory;
use NeuronCli\History\HistoryProjection;

/**
 * Keeps the Sessions of an Agent in a directory, one file per Session.
 *
 * This is what a Host Application that configures nothing gets. The files and
 * their format belong to Neuron AI's `FileChatHistory`; the only decision
 * taken here is where they live and how a key is minted.
 *
 * Listing them stays on the same footing: a Session is read by reopening the
 * conversation through Neuron AI, never by parsing what it stored. The file
 * itself is asked one thing only — when it was last written — because that is
 * the one fact the conversation does not carry.
 */
final readonly class FileSessionStore implements SessionStore
{
    /**
     * Relative to the working directory of the Host Application, so Sessions
     * follow the project rather than the machine.
     */
    private const string DEFAULT_DIRECTORY = '.neuron/sessions';

    /**
     * `FileChatHistory` names its files itself, from a prefix and an
     * extension it takes as arguments. Passing them on both sides — opening a
     * Session and finding which ones exist — keeps the two from drifting.
     */
    private const string FILE_PREFIX = 'neuron_';

    private const string FILE_EXTENSION = '.chat';

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
            prefix: self::FILE_PREFIX,
            ext: self::FILE_EXTENSION,
        );
    }

    public function list(): array
    {
        $summaries = [];

        foreach ($this->storedFiles() as $path) {
            $key = $this->keyOf($path);
            // Read through Neuron AI and projected the way the History is
            // painted, so a title says what the conversation would show.
            $title = HistoryProjection::openingWords(
                $this->open($key)->getMessages(),
            );

            if ($title === null) {
                continue;
            }

            $summaries[] = new SessionSummary(
                $key,
                $this->lastWrittenTo($path),
                $title,
            );
        }

        usort(
            $summaries,
            static fn (
                SessionSummary $one,
                SessionSummary $other,
            ): int => $other->lastUsedAt <=> $one->lastUsedAt,
        );

        return $summaries;
    }

    /**
     * A key is only ever a name for one conversation, so it has to be new and
     * usable as a file name, and nothing more.
     */
    private function mintKey(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * @return list<string>
     */
    private function storedFiles(): array
    {
        return glob(
            $this->directory . '/' . self::FILE_PREFIX
                . '*' . self::FILE_EXTENSION,
        ) ?: [];
    }

    private function keyOf(string $path): string
    {
        return substr(
            basename($path, self::FILE_EXTENSION),
            strlen(self::FILE_PREFIX),
        );
    }

    private function lastWrittenTo(string $path): DateTimeImmutable
    {
        $writtenAt = filemtime($path) ?: 0;

        return (new DateTimeImmutable('@' . $writtenAt))
            ->setTimezone(new DateTimeZone(date_default_timezone_get()));
    }
}
