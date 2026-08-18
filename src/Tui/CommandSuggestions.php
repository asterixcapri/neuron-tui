<?php

declare(strict_types=1);

namespace NeuronCli\Tui;

use NeuronCli\Conversation\Command;
use NeuronCli\Conversation\RunsWhileWorking;
use Symfony\Component\Tui\Widget\AbstractWidget;
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
 * What is on screen is read from the draft and from the turn: a single line
 * beginning with a slash and no whitespace yet is a name being written, and
 * anything else takes the suggestions away. With nothing to suggest they
 * stay, carrying the one line that says so, because there being nothing is
 * itself worth reading before Enter is pressed.
 *
 * While the Agent works the list carries the commands that run there and
 * nothing else. The Conversation TUI turns away a command that does not say
 * in its type that it runs mid-turn, so offering that name meanwhile would
 * promise a run that will not happen — and where none of the mounted
 * commands runs mid-turn, the line that says nothing matches is the honest
 * answer. The whole list is back when the turn ends.
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
     * A line for each mounted command, in the order they were named.
     *
     * @var list<array{value: string, label: string, description: string}>
     */
    private readonly array $allLines;

    /**
     * The same, kept to the commands a turn under way does not turn away.
     *
     * @var list<array{value: string, label: string, description: string}>
     */
    private readonly array $linesWhileWorking;

    /**
     * What is being written now, kept so that a turn beginning or ending can
     * ask the question again of an unchanged draft.
     */
    private string $draft = '';

    /**
     * Whether the Agent is working, which is what narrows the list.
     */
    private bool $working = false;

    /**
     * The lines the list was last given, so that it is only handed a new set
     * when the set is new: being given one moves the selection back to the
     * top.
     *
     * @var list<array{value: string, label: string, description: string}>
     */
    private array $listedLines = [];

    private ?AbstractWidget $onScreen = null;

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

        $this->allLines = self::linesFor($commands);
        $this->linesWhileWorking = self::linesFor(array_values(array_filter(
            $commands,
            static fn (Command $command): bool
                => $command instanceof RunsWhileWorking,
        )));
        $this->list = new SelectListWidget([], self::VISIBLE_LINES);
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
        $this->draft = $draft;
        $this->show();
    }

    /**
     * Tells the suggestions a turn is in flight. `ready()` is the counterpart.
     *
     * A turn ends under a name still being written, so what is on screen is
     * asked again here rather than waiting for the next keystroke, and the
     * two ends of a turn say it the same way.
     */
    public function working(): void
    {
        $this->working = true;
        $this->show();
    }

    public function ready(): void
    {
        $this->working = false;
        $this->show();
    }

    /**
     * Puts on screen what the draft and the turn together ask for.
     */
    private function show(): void
    {
        if (!self::isNameBeingWritten($this->draft)) {
            $this->hide();

            return;
        }

        $lines = $this->working
            ? $this->linesWhileWorking
            : $this->allLines;

        if ($lines === []) {
            $this->nothingMatches->setText(
                'No commands match "'
                    . DisplayableText::preview($this->draft, self::DRAFT_WIDTH)
                    . '"',
            );
            $this->put($this->nothingMatches);

            return;
        }

        if ($lines !== $this->listedLines) {
            $this->listedLines = $lines;
            $this->list->setItems($lines);
        }

        $this->put($this->list);
    }

    /**
     * Makes the given widget the one the suggestions carry, if it is not
     * already.
     */
    private function put(AbstractWidget $widget): void
    {
        if ($this->onScreen === $widget) {
            return;
        }

        $this->widget->clear();
        $this->widget->add($widget);
        $this->onScreen = $widget;
    }

    /**
     * Takes the suggestions off screen, leaving the draft where it is.
     */
    private function hide(): void
    {
        if (!$this->onScreen instanceof AbstractWidget) {
            return;
        }

        $this->widget->clear();
        $this->onScreen = null;
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
