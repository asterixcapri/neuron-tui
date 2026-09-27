<?php

declare(strict_types=1);

namespace NeuronTui\View;

use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ContentBlocks\VideoContent;
use NeuronAI\Chat\Messages\Message;

/** Formats one message for terminal presentation without changing it. */
final readonly class MessageTextFormatter
{
    private const int FILENAME_WIDTH = 80;

    public static function format(Message $message): string
    {
        $parts = [];

        foreach ($message->getContentBlocks() as $block) {
            $content = match (true) {
                $block instanceof ReasoningContent => null,
                $block instanceof TextContent => $block->getContent(),
                $block instanceof ImageContent => '[Image]',
                $block instanceof FileContent => self::filePlaceholder($block),
                $block instanceof AudioContent => '[Audio]',
                $block instanceof VideoContent => '[Video]',
                default => null,
            };

            if ($content !== null && $content !== '') {
                $parts[] = $content;
            }
        }

        return implode("\n\n", $parts);
    }

    private static function filePlaceholder(FileContent $file): string
    {
        if ($file->filename === null) {
            return '[File]';
        }

        // Safe first, so a stripped escape sequence cannot forge the
        // separator that basename() then splits on.
        $filename = DisplayableText::safe($file->filename);
        $filename = basename(str_replace('\\', '/', $filename));
        $filename = DisplayableText::preview($filename, self::FILENAME_WIDTH);

        return $filename === '' ? '[File]' : '[File: ' . $filename . ']';
    }
}
