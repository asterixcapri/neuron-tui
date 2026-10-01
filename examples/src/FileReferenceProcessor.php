<?php

declare(strict_types=1);

namespace NeuronTuiDemo;

use InvalidArgumentException;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronChatCore\Message\UserMessageProcessorInterface;
use RuntimeException;

use function array_unique;
use function file_get_contents;
use function is_dir;
use function is_file;
use function is_string;
use function preg_match;
use function preg_match_all;
use function realpath;
use function str_starts_with;

/** Expands @file references for the Agent without displaying the added contents. */
final readonly class FileReferenceProcessor implements UserMessageProcessorInterface
{
    private string $directory;

    public function __construct(string $directory)
    {
        $resolved = realpath($directory);
        if ($resolved === false || !is_dir($resolved)) {
            throw new InvalidArgumentException('The reference directory must exist.');
        }

        $this->directory = $resolved;
    }

    public function forAgent(UserMessage $message): UserMessage
    {
        $prepared = clone $message;
        preg_match_all('~(?<!\\S)@([A-Za-z0-9_./-]+)~', $message->getContent() ?? '', $matches);

        foreach (array_unique($matches[1]) as $reference) {
            $path = realpath($this->directory . '/' . $reference);
            if ($path === false || !is_file($path) || !str_starts_with($path, $this->directory . '/')) {
                throw new RuntimeException("Reference @{$reference} must be a file inside the example directory.");
            }

            $contents = file_get_contents($path);
            if ($contents === false || preg_match('//u', $contents) !== 1) {
                throw new RuntimeException("Reference @{$reference} must be a readable UTF-8 text file.");
            }

            $context = new TextContent("Referenced file: {$reference}\n\n{$contents}");
            $context->addMetadata('fileReference', $reference);
            $prepared->addContent($context);
        }

        return $prepared;
    }

    public function forDisplay(UserMessage $message): UserMessage
    {
        $display = clone $message;
        $contents = [];
        foreach ($message->getContentBlocks() as $block) {
            if ($block instanceof TextContent && is_string($block->getMetadata('fileReference'))) {
                continue;
            }

            $contents[] = clone $block;
        }

        $display->setContents($contents);

        return $display;
    }
}
