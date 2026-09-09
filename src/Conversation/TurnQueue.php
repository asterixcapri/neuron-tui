<?php

declare(strict_types=1);

namespace NeuronTui\Conversation;

/**
 * What becomes of a message written while the Agent is still answering.
 *
 * The queue state, the messages waiting behind it and the transitions
 * between them are all here, and nothing else is: the queue reads no input,
 * paints nothing and never touches the Agent. Every rule about ordering can
 * therefore be exercised in memory, with no event loop and no provider.
 *
 * A turn is occupied from the moment a message is taken, not from the moment
 * the Agent receives it, so a second message written in between still waits
 * its turn. Preparing a Turn is one transition, whether the message came
 * straight from the composer or from the queue.
 *
 * @internal
 */
final class TurnQueue
{
    private TurnQueueState $state = TurnQueueState::Idle;

    private ?string $readyMessage = null;

    /** @var list<string> */
    private array $queuedMessages = [];

    /**
     * Takes a message written by the person.
     *
     * Returns the message when it starts a turn now, and null when a turn is
     * already under way and the message has joined the queue behind it.
     */
    public function accept(string $message): ?string
    {
        if ($this->state !== TurnQueueState::Idle) {
            $this->queuedMessages[] = $message;

            return null;
        }

        return $this->prepareTurn($message);
    }

    /**
     * Hands over the message the Agent is to answer, once.
     *
     * Returns null when no ready message awaits execution handoff. Taking the
     * message does not invoke the Agent.
     */
    public function takeForExecution(): ?string
    {
        if ($this->state !== TurnQueueState::Ready) {
            return null;
        }

        $message = $this->readyMessage;
        $this->readyMessage = null;
        $this->state = TurnQueueState::Running;

        return $message;
    }

    /**
     * Acknowledges completion and prepares the next waiting Turn.
     *
     * Returns the message at the head of the queue, whose turn starts now, or
     * null when nothing was waiting.
     */
    public function finishAndAdvance(): ?string
    {
        $this->state = TurnQueueState::Idle;

        if ($this->queuedMessages === []) {
            return null;
        }

        return $this->prepareTurn(array_shift($this->queuedMessages));
    }

    /**
     * The messages still waiting, in the order they will be sent.
     *
     * @return list<string>
     */
    public function queuedMessages(): array
    {
        return $this->queuedMessages;
    }

    /**
     * Whether a turn is under way, from the moment a message is taken until
     * completion has been acknowledged by the queue.
     *
     * What a caller may not do mid-turn is its own business; this only says
     * when a turn occupies the conversation.
     */
    public function isBusy(): bool
    {
        return $this->state !== TurnQueueState::Idle;
    }

    private function prepareTurn(string $message): string
    {
        $this->readyMessage = $message;
        $this->state = TurnQueueState::Ready;

        return $message;
    }
}
