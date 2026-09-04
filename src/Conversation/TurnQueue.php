<?php

declare(strict_types=1);

namespace NeuronTui\Conversation;

/**
 * What becomes of an input accepted while the Agent is still answering.
 *
 * The states of a turn, the messages waiting behind it and the transitions
 * between them are all here, and nothing else is: the queue reads no input,
 * paints nothing and never touches the Agent. Every rule about ordering can
 * therefore be exercised in memory, with no event loop and no provider.
 *
 * A turn is occupied from the moment an input is taken, not from the moment
 * the Agent receives it, so another input accepted in between still waits.
 * Starting a turn is one transition, whether the input came from the person,
 * a Subagent or the queue.
 *
 * @internal
 */
final class TurnQueue
{
    private TurnState $state = TurnState::Idle;

    private ?ConversationInput $accepted = null;

    /** @var list<ConversationInput> */
    private array $queued = [];

    /**
     * Takes an input for the Agent in charge.
     *
     * Returns the input when it starts a turn now, and null when a turn is
     * already under way and the input has joined the queue behind it.
     */
    public function accept(ConversationInput $input): ?ConversationInput
    {
        if ($this->state !== TurnState::Idle) {
            $this->queued[] = $input;

            return null;
        }

        return $this->start($input);
    }

    /**
     * Hands over the input the Agent is to answer, once.
     *
     * Returns null when no accepted message is waiting to be sent, which is
     * every moment except the one right after a turn starts.
     */
    public function beginWorking(): ?ConversationInput
    {
        if ($this->state !== TurnState::Accepted) {
            return null;
        }

        $input = $this->accepted;
        $this->accepted = null;
        $this->state = TurnState::Working;

        return $input;
    }

    /**
     * Closes the turn the Agent was answering.
     *
     * Returns the input at the head of the queue, whose turn starts now, or
     * null when nothing was waiting.
     */
    public function finishWorking(): ?ConversationInput
    {
        $this->state = TurnState::Idle;

        if ($this->queued === []) {
            return null;
        }

        return $this->start(array_shift($this->queued));
    }

    /**
     * The inputs still waiting, in the order they will be sent.
     *
     * @return list<ConversationInput>
     */
    public function queued(): array
    {
        return $this->queued;
    }

    /**
     * Whether a turn is under way, from the moment an input is taken until
     * the Agent has finished answering it.
     *
     * What a caller may not do mid-turn is its own business; this only says
     * when a turn occupies the conversation.
     */
    public function isBusy(): bool
    {
        return $this->state !== TurnState::Idle;
    }

    private function start(ConversationInput $input): ConversationInput
    {
        $this->accepted = $input;
        $this->state = TurnState::Accepted;

        return $input;
    }
}
