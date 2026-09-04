<?php

declare(strict_types=1);

namespace NeuronTui\Conversation;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;

/**
 * A completed reply sent back by one Subagent.
 *
 * This is an input to the Agent in charge, not a second result from the tool
 * that started child work and not something written by the person.
 */
final readonly class SubagentReply implements ConversationInputInterface
{
    public const string HISTORY_PROVENANCE = 'neuron_tui_subagent_reply';

    public function __construct(
        public string $subagentId,
        public string $contents,
    ) {
    }

    public function message(): Message
    {
        return (new UserMessage(
            "Subagent reply\n"
                . "Subagent ID: {$this->subagentId}\n\n"
                . $this->contents,
        ))->addMetadata(self::HISTORY_PROVENANCE, $this->subagentId);
    }
}
