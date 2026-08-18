<?php

declare(strict_types=1);

namespace NeuronCli\Tui;

use NeuronCli\Conversation\Command;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * The mounted commands shown while a name is written after a slash.
 *
 * These are the Command suggestions, and they are not the Picker: nothing is
 * suspended, the list is never given the focus, and the draft stays where it
 * was being written. The select list underneath is the same widget the Picker
 * uses — for the bounded height and the scroll counter it draws by itself —
 * and nothing else is shared.
 *
 * What is on screen is read from the draft alone: a single line beginning
 * with a slash and no whitespace yet is a name being written, and anything
 * else takes the suggestions away. With nothing to suggest they stay,
 * carrying the one line that says so, because there being nothing is itself
 * worth reading before Enter is pressed.
 *
 * @internal
 */
final class CommandSuggestions
{
    private const int VISIBLE_LINES = 8;

    /**
     * The width the list gives a name before cutting it silently, so a name
     * is shortened here instead, where the cut is marked.
     */
    private const int NAME_WIDTH = 30;

    /**
     * How much of a description a line carries. Longer than a name because
     * it is the half that says what a command does.
     */
    private const int DESCRIPTION_WIDTH = 60;

    /**
     * How much of the draft the line that says nothing matches repeats, so
     * that a long one does not push what it is saying off the screen.
     */
    private const int DRAFT_WIDTH = 40;

    private readonly ContainerWidget $widget;

    private readonly SelectListWidget $list;

    private readonly TextWidget $nothingMatches;

    /**
     * Whether the commands to suggest fill a list or leave it empty. Read
     * once: they are the ones mounted when the terminal was built.
     */
    private readonly bool $anyToSuggest;

    private bool $onScreen = false;

    /**
     * @param list<Command> $commands
     *     the mounted commands, in the order the Host Application named them
     */
    public function __construct(array $commands)
    {
        $this->widget = new ContainerWidget();
        $this->widget->addStyleClass('suggestions');
        $this->nothingMatches = new TextWidget('');
        $this->nothingMatches->addStyleClass('suggestions-empty');

        $lines = self::linesFor($commands);
        $this->anyToSuggest = $lines !== [];
        $this->list = new SelectListWidget($lines, self::VISIBLE_LINES);
        $this->list->addStyleClass('suggestions-list');
    }

    public function widget(): ContainerWidget
    {
        return $this->widget;
    }

    /**
     * Reads the draft and puts the suggestions on screen, or takes them off.
     *
     * The one way in: whatever moved the composer — a key, or a draft taken
     * away — says so here with what is left of it.
     */
    public function draftChanged(string $draft): void
    {
        if (!self::isNameBeingWritten($draft)) {
            $this->hide();

            return;
        }

        if (!$this->anyToSuggest) {
            $this->nothingMatches->setText(
                'No commands match "'
                    . DisplayableText::preview($draft, self::DRAFT_WIDTH)
                    . '"',
            );
        }

        if ($this->onScreen) {
            return;
        }

        $this->widget->add(
            $this->anyToSuggest ? $this->list : $this->nothingMatches,
        );
        $this->onScreen = true;
    }

    /**
     * Takes the suggestions off screen, leaving the draft where it is.
     */
    private function hide(): void
    {
        if (!$this->onScreen) {
            return;
        }

        $this->widget->clear();
        $this->onScreen = false;
    }

    /**
     * A line for each command to suggest, in the order they were mounted.
     *
     * A name and a description are the Host Application's own text, so both
     * are made safe here: the list puts what it is given on the terminal as
     * it stands.
     *
     * @param list<Command> $commands
     *
     * @return list<array{value: string, label: string, description: string}>
     */
    private static function linesFor(array $commands): array
    {
        $lines = [];

        foreach ($commands as $command) {
            $lines[] = [
                'value' => $command->name(),
                'label' => DisplayableText::preview(
                    $command->name(),
                    self::NAME_WIDTH,
                ),
                'description' => DisplayableText::preview(
                    $command->describe(),
                    self::DESCRIPTION_WIDTH,
                ),
            ];
        }

        return $lines;
    }

    /**
     * Whether the draft is a name being written after a slash.
     *
     * One line, a slash to open it and no whitespace since: a space begins
     * the arguments, a new line makes the draft a message, and a draft that
     * lost its slash is a message too. A slash anywhere but first is text
     * for the Agent and is never one of these.
     *
     * Read byte by byte rather than as UTF-8: a draft that was pasted in is
     * bytes nobody validated, and asking whether a name is being written
     * must answer that question rather than fail on the encoding.
     */
    private static function isNameBeingWritten(string $draft): bool
    {
        return preg_match('/^\/\S*\z/', $draft) === 1;
    }
}
