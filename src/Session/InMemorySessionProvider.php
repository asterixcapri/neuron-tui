<?php

declare(strict_types=1);

namespace NeuronTui\Session;

use DateTimeImmutable;
use InvalidArgumentException;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronTui\History\HistoryProjection;

/**
 * Provides the Sessions of an Agent from memory, for as long as the process
 * runs.
 *
 * This is what a Host Application that configures nothing gets, and it takes
 * nothing: no directory, no credentials, no decision about where a
 * conversation should live. It is the promise Neuron AI already makes to an
 * Agent given no History at all — a conversation that works completely and
 * ends with the process — extended to all three Commands. A Host
 * Application that wants its conversations kept passes a provider that keeps
 * them.
 *
 * Nothing in memory records when a conversation was written to, so the order
 * the Sessions were minted in stands for how recently they were used: the
 * newest first. That is a stated convention, not a measurement.
 */
final class InMemorySessionProvider implements SessionProvider
{
    /**
     * The conversation of each Session and the moment its key was minted,
     * which is the only time this provider knows about it.
     *
     * @var array<string, array{
     *     history: ChatHistoryInterface,
     *     mintedAt: DateTimeImmutable,
     * }>
     */
    private array $sessions = [];

    private int $minted = 0;

    /**
     * A key is only ever a name for one conversation, and nothing outside this
     * process ever reads it, so counting the Sessions minted so far is enough
     * to tell them apart. The word in front of the count keeps a key from
     * being read as a number when it names an entry in an array.
     */
    public function start(): ChatHistoryInterface
    {
        $key = 'session-' . ++$this->minted;
        $mintedAt = new DateTimeImmutable();
        $history = new InMemoryChatHistory();
        $this->sessions[$key] = [
            'history' => $history,
            'mintedAt' => $mintedAt,
        ];

        return $history;
    }

    public function list(): array
    {
        $listed = [];

        foreach ($this->sessions as $key => $session) {
            $title = HistoryProjection::openingWords(
                $session['history']->getMessages(),
            );

            if ($title === null) {
                continue;
            }

            $listed[] = new Session($key, $session['mintedAt'], $title);
        }

        return array_reverse($listed);
    }

    /**
     * Every key this provider hands out it minted itself, so a key it does not
     * know names no Session of this Agent. Saying so is better than starting a
     * conversation nobody asked for under a name nobody minted.
     */
    public function resume(string $key): ChatHistoryInterface
    {
        return $this->sessions[$key]['history']
            ?? throw new InvalidArgumentException(
                'No Session of this Agent is named by that key.',
            );
    }
}
