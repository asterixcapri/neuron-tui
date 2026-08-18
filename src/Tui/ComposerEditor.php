<?php

declare(strict_types=1);

namespace NeuronCli\Tui;

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

    /**
     * Writes the given text in place of the draft, leaving the cursor after
     * it so that whoever is writing carries on from its end.
     *
     * Setting the text alone puts the cursor back at the start, which is
     * behind what was just written rather than after it.
     */
    public function writeDraft(string $draft): void
    {
        $this->setText($draft);
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
