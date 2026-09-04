<?php

declare(strict_types=1);

namespace NeuronTui\Conversation;

use NeuronAI\Chat\Messages\Message;

/**
 * One complete input waiting for the Agent in charge.
 *
 * Inputs from different sources share a Turn queue without losing the
 * provenance that determines how they are shown and described to the model.
 *
 * @internal
 */
interface ConversationInputInterface
{
    /**
     * The message the Agent receives for this Turn.
     */
    public function message(): Message;
}
