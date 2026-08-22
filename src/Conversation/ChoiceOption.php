<?php

declare(strict_types=1);

namespace NeuronCli\Conversation;

use InvalidArgumentException;
use NeuronCli\Tui\DisplayableText;

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
        if (DisplayableText::singleLine($label) === '') {
            throw new InvalidArgumentException(
                'Choice option labels must not be empty.',
            );
        }

        if (
            $detail !== null
            && DisplayableText::singleLine($detail) === ''
        ) {
            throw new InvalidArgumentException(
                'Choice option details must not be empty.',
            );
        }
    }
}
