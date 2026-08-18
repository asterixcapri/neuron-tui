<?php

declare(strict_types=1);

namespace NeuronCli\Tui;

use NeuronCli\Conversation\Command;
use Symfony\Component\Tui\Style\Style;
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
 * What is on screen is read from the draft alone: a single line beginning
 * with a slash and no whitespace yet is a name being written, and anything
 * else takes the suggestions away. The names that carry what has been
 * written stay, each saying with a bold stretch why it is there; when none
 * do, the one line that says so takes the list's place, because there being
 * nothing is itself worth reading before Enter is pressed.
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
     * How the stretch of a name that matched is told apart from the rest of
     * it. A style sheet dresses whole lines, and this is half a word.
     */
    private readonly Style $emphasis;

    /**
     * What there is to suggest: for each mounted command the name it answers
     * to, and the two halves of the line it would be read on.
     *
     * Read once, in the order the Host Application mounted them, which is
     * the order commands matching alike keep on screen.
     *
     * @var list<array{name: string, label: string, description: string}>
     */
    private readonly array $suggestible;

    /**
     * The lines the list is holding, so that it is only ever given a set it
     * is not already showing.
     *
     * @var list<array{value: string, label: string, description: string}>|null
     */
    private ?array $shown = null;

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
        $this->emphasis = new Style(bold: true);
        $this->suggestible = self::suggestible($commands);
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
        if (!self::isNameBeingWritten($draft)) {
            $this->hide();

            return;
        }

        $lines = $this->linesMatching($draft);

        if ($lines === []) {
            $this->nothingMatches->setText(
                'No commands match "'
                    . DisplayableText::preview($draft, self::DRAFT_WIDTH)
                    . '"',
            );
            $this->show($this->nothingMatches);

            return;
        }

        if ($lines !== $this->shown) {
            // A different set of lines is a different list to read, so it is
            // handed over whole and the selection goes back to the top with
            // it: whoever is writing is narrowing, not scrolling.
            $this->list->setItems($lines);
            $this->shown = $lines;
        }

        $this->show($this->list);
    }

    /**
     * Puts the given band on screen, in place of whatever was there.
     */
    private function show(AbstractWidget $band): void
    {
        if ($this->onScreen === $band) {
            return;
        }

        $this->widget->clear();
        $this->widget->add($band);
        $this->onScreen = $band;
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
        // The list is given its lines again next time it is shown, so what
        // was selected before does not outlive the writing that chose it.
        $this->shown = null;
    }

    /**
     * The lines to read for a draft, in the order they are read in.
     *
     * A name stays if what has been written after the slash appears inside
     * it, in one contiguous stretch and whatever the case: `/wind` finds
     * `/rewind`, `/rwd` finds nothing. The description is not searched — one
     * looks here among the names, not among the meanings.
     *
     * The order is three sets one after the other — the name written in
     * full, then the names that begin with it, then the names that merely
     * carry it — and inside each the order the Host Application mounted
     * them. It is not a score: the order can be told from the code without
     * running it.
     *
     * @return list<array{value: string, label: string, description: string}>
     */
    private function linesMatching(string $draft): array
    {
        $written = mb_strtolower(DisplayableText::safe($draft));
        $after = mb_substr($written, 1);
        $exact = [];
        $beginning = [];
        $carrying = [];

        foreach ($this->suggestible as $suggestion) {
            $name = mb_strtolower($suggestion['label']);
            $at = $after === '' ? false : mb_strpos($name, $after);

            if ($after !== '' && $at === false) {
                continue;
            }

            $line = [
                'value' => $suggestion['name'],
                'label' => $at === false
                    ? $suggestion['label']
                    : $this->emphasising(
                        $suggestion['label'],
                        $at,
                        mb_strlen($after),
                    ),
                'description' => $suggestion['description'],
            ];

            if ($name === $written) {
                $exact[] = $line;
            } elseif (str_starts_with($name, $written)) {
                $beginning[] = $line;
            } else {
                $carrying[] = $line;
            }
        }

        return [...$exact, ...$beginning, ...$carrying];
    }

    /**
     * The name with the stretch that matched in bold, so that a line says at
     * a glance why it is there. With a contiguous stretch there is only ever
     * the one.
     */
    private function emphasising(string $name, int $at, int $length): string
    {
        return mb_substr($name, 0, $at)
            . $this->emphasis->apply(mb_substr($name, $at, $length))
            . mb_substr($name, $at + $length);
    }

    /**
     * What there is to suggest, one entry per mounted command.
     *
     * A name and a description are the Host Application's own text, so both
     * are made safe here: the list puts what it is given on the terminal as
     * it stands. What is matched and emphasised is the safe name, so that
     * the bold stretch falls where the name is read; the name a completion
     * would write is kept beside it.
     *
     * @param list<Command> $commands
     *
     * @return list<array{name: string, label: string, description: string}>
     */
    private static function suggestible(array $commands): array
    {
        $suggestible = [];

        foreach ($commands as $command) {
            $suggestible[] = [
                'name' => $command->name(),
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

        return $suggestible;
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
