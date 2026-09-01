<?php

declare(strict_types=1);

namespace NeuronTui\Tui;

use NeuronTui\Conversation\Command;
use NeuronTui\Conversation\RunsWhileWorking;
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
 * What is on screen is read from the draft and from the turn: a single line
 * beginning with a slash and no whitespace yet is a name being written, and
 * anything else takes the suggestions away. The names that carry what has
 * been written stay, each saying with a bold stretch why it is there; when
 * none do, the one line that says so takes the list's place, because there
 * being nothing is itself worth reading before Enter is pressed.
 *
 * The keys are asked for from outside: whoever holds them says which line is
 * chosen, asks for the name a completion or immediate run would write, or
 * takes the band away for an Escape. Two questions answer for all of them:
 * whether anything is on screen at all, which is what Escape takes away, and
 * whether what is on screen is a list rather than the one line that says
 * nothing matches, which is what ↑↓, Tab and Enter have to work on.
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
     * How the stretch of a name that matched is told apart from the rest of
     * it. A style sheet dresses whole lines, and this is half a word.
     */
    private readonly Style $emphasis;

    /**
     * What there is to suggest: for each mounted command the name it answers
     * to, the name a draft is matched against, and the two halves of the
     * line it would be read on.
     *
     * Read once, in the order the Host Application mounted them, which is
     * the order commands matching alike keep on screen.
     *
     * `$suggestibleWhileWorking` is the same, kept to the commands a turn
     * under way does not turn away.
     *
     * @var list<array{
     *     answersTo: string,
     *     name: string,
     *     label: string,
     *     description: string,
     * }>
     */
    private readonly array $suggestible;

    /**
     * @var list<array{
     *     answersTo: string,
     *     name: string,
     *     label: string,
     *     description: string,
     * }>
     */
    private readonly array $suggestibleWhileWorking;

    /**
     * The lines the list is holding, so that it is only ever given a set it
     * is not already showing.
     *
     * @var list<array{value: string, label: string, description: string}>|null
     */
    private ?array $shown = null;

    /**
     * Which of the shown lines is chosen, as a place among them. Kept here
     * as well as in the list because the list is handed a set of lines and
     * answers about the one under the arrow, while what moves the arrow is
     * a key the list never sees.
     */
    private int $chosen = 0;

    /**
     * Whether Escape has taken the band away from a draft that would still
     * have it on screen. Writing brings it back; a turn beginning or ending
     * underneath does not.
     */
    private bool $dismissed = false;

    /**
     * What is being written now, kept so that a turn beginning or ending can
     * ask the question again of an unchanged draft.
     */
    private string $draft = '';

    /**
     * Whether the Agent is working, which is what narrows the list.
     */
    private bool $working = false;

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
        $this->suggestibleWhileWorking = self::suggestible(array_values(
            array_filter(
                $commands,
                static fn (Command $command): bool
                    => $command instanceof RunsWhileWorking,
            ),
        ));
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
        // A draft that changed is a name being written again, so what
        // Escape took away comes back with the writing that asks for it.
        $this->dismissed = false;
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
     * Whether anything of the suggestions occupies its band, the line that
     * says nothing matches included.
     *
     * That line is on screen and covers the conversation just as the list
     * does, so it is what Escape takes away as well.
     */
    public function isOnScreen(): bool
    {
        return $this->onScreen !== null;
    }

    /**
     * Whether there is a list on screen to move through and take from.
     *
     * The one line that says nothing matches is not one: it is read, not
     * chosen from. So this is the answer to every question the keys ask —
     * whether ↑↓ have anywhere to go, whether Tab or Enter has a name to
     * take, whether Escape has a list to take away — and to what the status
     * line says while there is one.
     */
    public function isListOpen(): bool
    {
        return $this->onScreen === $this->list && $this->shown !== null;
    }

    /**
     * The selected full command name, or nothing where there is no list to
     * take from.
     */
    public function chosenName(): ?string
    {
        if (!$this->isListOpen()) {
            return null;
        }

        return $this->shown[$this->chosen]['value'] ?? null;
    }

    /**
     * Chooses the line above the one chosen now, the last one being above
     * the first. Answers whether there was a list to move through.
     */
    public function choosePrevious(): bool
    {
        return $this->moveBy(-1);
    }

    /**
     * Chooses the line below the one chosen now, the first one being below
     * the last.
     */
    public function chooseNext(): bool
    {
        return $this->moveBy(1);
    }

    /**
     * Takes the band away from a draft that would keep it, which is what
     * Escape means here. What is being written is left untouched, so a
     * second Escape reaches the composer and empties it as it always has.
     */
    public function dismiss(): void
    {
        $this->dismissed = true;
        $this->hide();
    }

    /**
     * Moves the chosen line by the given number of places, wrapping around
     * the ends the way the list does when it holds the keys itself.
     */
    private function moveBy(int $places): bool
    {
        if (!$this->isListOpen()) {
            return false;
        }

        $lines = count($this->shown ?? []);

        if ($lines === 0) {
            return false;
        }

        $this->chosen = ($this->chosen + $places + $lines) % $lines;
        $this->list->setSelectedIndex($this->chosen);

        return true;
    }

    /**
     * Puts on screen what the draft and the turn together ask for.
     */
    private function show(): void
    {
        if ($this->dismissed || !self::isNameBeingWritten($this->draft)) {
            $this->hide();

            return;
        }

        $lines = $this->linesMatching($this->draft);

        if ($lines === []) {
            $this->nothingMatches->setText(
                'No commands match "'
                    . DisplayableText::preview($this->draft, self::DRAFT_WIDTH)
                    . '"',
            );
            $this->put($this->nothingMatches);
            // The list is given its lines again when one matches next, so
            // what was selected before this does not come back with them.
            $this->shown = null;
            $this->chosen = 0;

            return;
        }

        if ($lines !== $this->shown) {
            // A different set of lines is a different list to read, so it is
            // handed over whole and the selection goes back to the top with
            // it: whoever is writing is narrowing, not scrolling.
            $this->list->setItems($lines);
            $this->shown = $lines;
            $this->chosen = 0;
        }

        $this->put($this->list);
    }

    /**
     * Puts the given band on screen, in place of whatever was there.
     */
    private function put(AbstractWidget $band): void
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
        if ($this->onScreen === null) {
            return;
        }

        $this->widget->clear();
        $this->onScreen = null;
        // The list is given its lines again next time it is shown, so what
        // was selected before does not outlive the writing that chose it.
        $this->shown = null;
        $this->chosen = 0;
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
     * While the Agent works only the commands that run there are walked, so
     * a name that would be turned away is never suggested.
     *
     * @return list<array{value: string, label: string, description: string}>
     */
    private function linesMatching(string $draft): array
    {
        $draft = DisplayableText::safe($draft);
        $written = mb_strtolower($draft);
        $after = mb_substr($draft, 1);
        $exact = [];
        $beginning = [];
        $carrying = [];

        $suggestible = $this->working
            ? $this->suggestibleWhileWorking
            : $this->suggestible;

        foreach ($suggestible as $suggestion) {
            $name = $suggestion['name'];

            if ($after !== '' && mb_stripos($name, $after) === false) {
                continue;
            }

            $line = [
                'value' => $suggestion['answersTo'],
                'label' => $this->emphasising($suggestion['label'], $after),
                'description' => $suggestion['description'],
            ];
            $lowered = mb_strtolower($name);

            if ($lowered === $written) {
                $exact[] = $line;
            } elseif (str_starts_with($lowered, $written)) {
                $beginning[] = $line;
            } else {
                $carrying[] = $line;
            }
        }

        return [...$exact, ...$beginning, ...$carrying];
    }

    /**
     * The line's name with the stretch that matched in bold, so that a line
     * says at a glance why it is there. With a contiguous stretch there is
     * only ever the one.
     *
     * A name too long to be read whole is read shortened, and a stretch that
     * fell in the part that was cut has nowhere to be shown: the name is
     * then left as it is, still there for having matched.
     */
    private function emphasising(string $label, string $written): string
    {
        $at = $written === '' ? false : mb_stripos($label, $written);

        if ($at === false) {
            return $label;
        }

        $length = mb_strlen($written);

        return mb_substr($label, 0, $at)
            . $this->emphasis->apply(mb_substr($label, $at, $length))
            . mb_substr($label, $at + $length);
    }

    /**
     * What there is to suggest, one entry per mounted command.
     *
     * A name and a description are the Host Application's own text, so both
     * are made safe here: the list puts what it is given on the terminal as
     * it stands. A name is kept three times over, because the three are not
     * the same string: the one a completion would write, the whole safe one
     * a draft is matched against, and the shortened one a line is read on.
     *
     * @param list<Command> $commands
     *
     * @return list<array{
     *     answersTo: string,
     *     name: string,
     *     label: string,
     *     description: string,
     * }>
     */
    private static function suggestible(array $commands): array
    {
        $suggestible = [];

        foreach ($commands as $command) {
            $suggestible[] = [
                'answersTo' => $command->name(),
                'name' => DisplayableText::safe($command->name()),
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
