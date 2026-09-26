<?php

declare(strict_types=1);

namespace NeuronTui\View\Widget;

use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\UserMessage;
use Symfony\Component\Tui\Event\ChangeEvent;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\EditorWidget;

/**
 * @internal
 */
final class ComposerEditor extends EditorWidget
{
    /**
     * What the editor answers with a move to the end of the line, there
     * being no other way in from here.
     */
    private const string TO_LINE_END = "\x05";

    /** What the editor answers with a move to the following logical line. */
    private const string TO_NEXT_LINE = "\x1b[B";

    private UserMessage $draft;

    public function __construct()
    {
        parent::__construct();
        $this->draft = new UserMessage('');
        $this->onChange(function (ChangeEvent $event): void {
            $this->draft = $this->message($event->getValue());
        });
    }

    /** Return the complete draft, optionally with prepared text, without changing it. */
    public function message(?string $text = null): UserMessage
    {
        $message = clone $this->draft;
        if ($text === null || $text === ($message->getContent() ?? '')) {
            return $message;
        }

        $message->setContents($text);
        foreach ($this->draft->getContentBlocks() as $block) {
            if (!$block instanceof TextContent) {
                $message->addContent($block);
            }
        }

        return $message;
    }

    public function hasAttachments(): bool
    {
        foreach ($this->draft->getContentBlocks() as $block) {
            if (!$block instanceof TextContent) {
                return true;
            }
        }

        return false;
    }

    public function setText(string $text): static
    {
        $this->draft = $this->message($text);

        return parent::setText($text);
    }

    /** Replace the whole draft, including attachments. */
    public function writeDraft(UserMessage $draft): void
    {
        $this->draft = clone $draft;
        parent::setText($draft->getContent() ?? '');
        $this->moveToEnd();
    }

    /**
     * Writes the given text in place of the draft, leaving the cursor after
     * it so that whoever is writing carries on from its end.
     *
     * Setting the text alone puts the cursor back at the start, which is
     * behind what was just written rather than after it.
     */
    public function writeText(string $draft): void
    {
        $this->setText($draft);
        $this->moveToEnd();
    }

    private function moveToEnd(): void
    {
        for ($line = substr_count($this->getText(), "\n"); $line > 0; --$line) {
            $this->handleInput(self::TO_NEXT_LINE);
        }

        $this->handleInput(self::TO_LINE_END);
    }

    /**
     * @return string[]
     */
    public function render(RenderContext $context): array
    {
        return array_slice(parent::render($context), 1, -1);
    }
}
