<?php

declare(strict_types=1);

namespace NeuronTui\Conversation;

/** Host-defined preparation and presentation of user messages. */
interface UserMessageProcessorInterface
{
    /**
     * Prepare ordinary submitted text before it is queued for the Agent.
     * Commands and prompts produced by commands bypass this method.
     * Throw to reject submission; the TUI shows the error and keeps the draft.
     */
    public function forAgent(string $input): string;

    /**
     * Present user text without changing History. Also called for queued
     * messages, loaded History and picker labels from any command. Must accept
     * arbitrary text, leave unrecognized content unchanged and be safe to call
     * repeatedly. Option values are never processed.
     * This is a display projection, not necessarily an inverse of forAgent().
     */
    public function forDisplay(string $content): string;
}
