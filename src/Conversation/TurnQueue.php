<?php

declare(strict_types=1);

namespace NeuronCli\Conversation;

/**
 * What becomes of a message written while the Agent is still answering.
 *
 * The states of a turn, the messages waiting behind it and the transitions
 * between them are all here, and nothing else is: the queue reads no input,
 * paints nothing and never touches the Agent. Every rule about ordering can
 * therefore be exercised in memory, with no event loop and no provider.
 *
 * A turn is occupied from the moment a message is taken, not from the moment
 * the Agent receives it, so a second message written in between still waits
 * its turn. Starting a turn is one transition, whether the message came
 * straight from the composer or from the queue.
 *
 * @internal
 */
final class TurnQueue
{
    private TurnState $state = TurnState::Idle;

    private ?string $accepted = null;

    /** @var list<string> */
    private array $queued = [];

    /**
     * Takes a message written by the person.
     *
     * Returns the message when it starts a turn now, and null when a turn is
     * already under way and the message has joined the queue behind it.
     */
    public function accept(string $message): ?string
    {
        if ($this->state !== TurnState::Idle) {
            $this->queued[] = $message;

            return null;
        }

        return $this->start($message);
    }

    /**
     * Hands over the message the Agent is to answer, once.
     *
     * Returns null when no accepted message is waiting to be sent, which is
     * every moment except the one right after a turn starts.
     */
    public function beginWorking(): ?string
    {
        if ($this->state !== TurnState::Accepted) {
            return null;
        }

        $message = $this->accepted;
        $this->accepted = null;
        $this->state = TurnState::Working;

        return $message;
    }

    /**
     * Closes the turn the Agent was answering.
     *
     * Returns the message at the head of the queue, whose turn starts now, or
     * null when nothing was waiting.
     */
    public function finishWorking(): ?string
    {
        $this->state = TurnState::Idle;

        if ($this->queued === []) {
            return null;
        }

        return $this->start(array_shift($this->queued));
    }

    /**
     * The messages still waiting, in the order they will be sent.
     *
     * @return list<string>
     */
    public function queued(): array
    {
        return $this->queued;
    }

    /**
     * Whether a turn is under way, from the moment a message is taken until
     * the Agent has finished answering it.
     *
     * What a caller may not do mid-turn is its own business; this only says
     * when a turn occupies the conversation.
     */
    public function isBusy(): bool
    {
        return $this->state !== TurnState::Idle;
    }

    private function start(string $message): string
    {
        $this->accepted = $message;
        $this->state = TurnState::Accepted;

        return $message;
    }
}
