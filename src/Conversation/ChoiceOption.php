<?php

declare(strict_types=1);

namespace NeuronCli\Conversation;

/**
 * One value a Slash command offers through the Picker.
 *
 * The key belongs to the command and comes back untouched when this option
 * is chosen. The label names it on screen; the optional detail explains it
 * without becoming a second kind of choice.
 */
final readonly class ChoiceOption
{
    public function __construct(
        public string $key,
        public string $label,
        public ?string $detail = null,
    ) {
    }
}
