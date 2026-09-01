<?php

declare(strict_types=1);

namespace NeuronTui\Session;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\FileChatHistory;
use NeuronTui\History\HistoryProjection;
use RuntimeException;

/**
 * Provides the Sessions of an Agent from a directory, one file per Session.
 *
 * This is what a Host Application that wants its conversations kept passes,
 * having decided that they are kept in files, and named the directory holding
 * them. The files and their format belong to Neuron AI's `FileChatHistory`;
 * the only decision taken here is how a key is minted.
 *
 * Listing them stays on the same footing: a Session is read by reopening the
 * conversation through Neuron AI, never by parsing what it stored. The file
 * itself supplies the two facts the conversation does not carry: when it was
 * last written and how many bytes it occupies.
 */
final readonly class FileSessionProvider implements SessionProvider
{
    /**
     * `FileChatHistory` names its files itself, from a prefix and an
     * extension it takes as arguments. Passing them on both sides — opening a
     * Session and finding which ones exist — keeps the two from drifting.
     */
    private const string FILE_PREFIX = '';

    private const string FILE_EXTENSION = '.json';

    /**
     * The directory is asked for rather than guessed, so a conversation only
     * ever lands where the Host Application said it should.
     */
    public function __construct(private string $directory) {}

    /**
     * Nothing is written until the conversation receives a message, so the
     * newly minted key and its History do not create a file by themselves.
     */
    public function start(): ChatHistoryInterface
    {
        return $this->history($this->mintKey());
    }

    public function list(): array
    {
        $sessions = [];

        foreach ($this->storedFiles() as $path) {
            $key = $this->keyOf($path);
            // Read through Neuron AI and projected the way the History is
            // painted, so a title says what the conversation would show.
            $title = HistoryProjection::openingWords(
                $this->history($key)->getMessages(),
            );

            if ($title === null) {
                continue;
            }

            $sessions[] = new Session(
                $key,
                $this->lastWrittenTo($path),
                $title,
                $this->storageSizeOf($path),
            );
        }

        usort(
            $sessions,
            static fn (
                Session $one,
                Session $other,
            ): int => $other->lastUsedAt <=> $one->lastUsedAt,
        );

        return $sessions;
    }

    public function resume(string $key): ChatHistoryInterface
    {
        foreach ($this->storedFiles() as $path) {
            if ($this->keyOf($path) === $key) {
                return $this->history($key);
            }
        }

        throw new InvalidArgumentException(
            'No Session is named by that key.',
        );
    }

    private function history(string $key): ChatHistoryInterface
    {
        return new FileChatHistory(
            $this->directory,
            $key,
            prefix: self::FILE_PREFIX,
            ext: self::FILE_EXTENSION,
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

    private function storageSizeOf(string $path): int
    {
        $size = filesize($path);

        if ($size === false) {
            throw new RuntimeException(
                'The Session file size could not be read.',
            );
        }

        return $size;
    }
}
