<?php

declare(strict_types=1);

namespace NeuronTui\Tui;

use Amp\DeferredFuture;
use Closure;
use InvalidArgumentException;
use LogicException;
use NeuronAI\Chat\Messages\Message;
use NeuronTui\Conversation\ChoiceOption;
use NeuronTui\Conversation\Command;
use NeuronTui\History\EntryKind;
use NeuronTui\History\HistoryProjection;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\ChangeEvent;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Terminal\TerminalInterface;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * Owns the visual state and terminal rendering of a Conversation TUI.
 *
 * @internal
 */
final class ConversationView
{
    private const string READY_STATUS =
        'ready · Enter sends · Shift+Enter adds a line · Ctrl+C exits';

    private const string WORKING_STATUS =
        'Enter queues · Shift+Enter adds a line';

    private const string SUGGESTING_STATUS =
        'suggesting · ↑↓ moves · Tab completes · Enter runs';

    /**
     * Where the keys the suggestions answer are listened for: before the
     * composer, which has the focus throughout, and after whatever the Host
     * Application asked to hear first.
     */
    private const int SUGGESTION_KEYS_PRIORITY = 50;

    private readonly Tui $tui;

    private readonly HistoryPane $history;

    private readonly ContainerWidget $queuedMessages;

    private readonly ComposerEditor $editor;

    private readonly TextWidget $status;

    private readonly ContainerWidget $composerRow;

    private readonly ContainerWidget $conversationControls;

    private readonly ContainerWidget $lowerPanel;

    private readonly WorkingIndicator $workingIndicator;

    private readonly Picker $picker;

    private readonly CommandSuggestions $suggestions;

    /**
     * The keys the suggestions answer while they are on screen.
     */
    private readonly Keybindings $keys;

    private ?HistoryEntry $activeAgentMessage = null;

    /**
     * Whether a turn is in flight, which is what the status line goes back
     * to saying once the suggestions are no longer on screen.
     */
    private bool $working = false;

    /**
     * The choice a command is waiting on, while one is open.
     *
     * @var DeferredFuture<string|null>|null
     */
    private ?DeferredFuture $choice = null;

    /**
     * Whether leaving the terminal is waiting on a choice to be let go.
     */
    private bool $leaving = false;

    /**
     * @param list<Command> $commands
     *     the mounted commands, in the order the Host Application named
     *     them, which are what the Command suggestions have to offer
     */
    public function __construct(
        private readonly TerminalInterface $terminal,
        string $title,
        string $subtitle,
        array $commands = [],
    ) {
        $this->tui = new Tui(
            ConversationStyleSheet::create(),
            $this->terminal,
        );
        $this->history = new HistoryPane($this->tui, $this->terminal);
        $this->workingIndicator = new WorkingIndicator($this->history);
        $this->queuedMessages = new ContainerWidget();
        $this->queuedMessages->addStyleClass('queued-messages');
        $this->editor = new ComposerEditor();
        $this->editor->addStyleClass('composer');
        $this->editor->setMinVisibleLines(1);
        $this->editor->setMaxVisibleLines(5);
        $this->editor->onCancel($this->clearDraft(...));
        $this->editor->onChange($this->draftChanged(...));
        $this->status = new TextWidget(self::READY_STATUS);
        $this->status->addStyleClass('status');
        $this->composerRow = new ContainerWidget();
        $this->composerRow->addStyleClass('composer-row');
        $prompt = new TextWidget('❯');
        $prompt->addStyleClass('composer-label');
        $this->composerRow->add($prompt);
        $this->composerRow->add($this->editor);
        $this->conversationControls = new ContainerWidget();
        $this->conversationControls->addStyleClass('conversation-controls');
        $this->lowerPanel = new ContainerWidget();
        $this->picker = new Picker(
            $this->closePicker(...),
            $this->abandon(...),
        );
        $this->suggestions = new CommandSuggestions($commands);
        $this->keys = new Keybindings([
            'suggestion-previous' => [Key::UP],
            'suggestion-next' => [Key::DOWN],
            'suggestion-complete' => [Key::TAB],
            'suggestion-run' => [Key::ENTER],
            'suggestion-close' => [Key::ESCAPE],
        ]);
        $this->tui->addListener(
            $this->handleSuggestionKeys(...),
            self::SUGGESTION_KEYS_PRIORITY,
        );

        $this->build($title, $subtitle);
    }

    /**
     * @param Closure(SubmitEvent): void $listener
     */
    public function onSubmit(Closure $listener): void
    {
        $this->editor->onSubmit($listener);
    }

    /**
     * @param Closure(InputEvent): void $listener
     */
    public function onInput(Closure $listener): void
    {
        $this->tui->addListener($listener, 100);
    }

    /**
     * @param Closure(): bool $listener
     */
    public function onTick(Closure $listener): void
    {
        $this->tui->onTick($listener);
    }

    public function run(): void
    {
        $this->tui->run();
    }

    public function stop(): void
    {
        if ($this->choice instanceof DeferredFuture) {
            // A choice still open holds the command that asked for it, and a
            // loop that goes now would leave that command suspended for
            // good. So the choice is answered with nothing and leaving is
            // taken up again where the waiting ends.
            $this->leaving = true;
            $this->abandon();

            return;
        }

        $this->tui->stop();
    }

    public function paintPendingChanges(): void
    {
        $this->tui->processRender();
    }

    /**
     * Paints the given History, replacing whatever is on screen.
     *
     * Runnable at any moment: opening the TUI paints the History the Agent
     * arrived with, and changing Session paints the new one over it.
     *
     * @param array<Message> $messages
     */
    public function showHistory(array $messages): void
    {
        // The indicator lets go of its own line before the pane empties, so it
        // is not left holding a handle to an entry that no longer exists.
        $this->workingIndicator->stop();
        $this->history->clear();
        $this->activeAgentMessage = null;

        foreach (HistoryProjection::entriesFor($messages) as $entry) {
            if ($entry->kind === EntryKind::Tool) {
                $this->history->addNote($entry->text, 'tool');

                continue;
            }

            $spokenByPerson = $entry->kind === EntryKind::Person;

            $this->history->addMessage(
                $spokenByPerson ? '❯' : '●',
                $entry->text,
                $spokenByPerson ? 'user' : 'agent',
            );
        }
    }

    /**
     * Throws away whatever is in the composer.
     *
     * A draft belongs to the Session it was written in, so changing Session
     * takes it away rather than carrying it over.
     */
    public function emptyComposer(): void
    {
        $this->writeDraft('');
    }

    /**
     * Puts the TUI in the Picker and waits there for an answer, which is the
     * key of the line a person chose, or nothing if they cancelled.
     *
     * From here on the keys belong to the list and the composer is out of
     * reach, which is why the draft goes now rather than when a line is
     * finally chosen. The wait suspends the caller alone: the event loop the
     * TUI runs on goes on ticking, so the screen keeps being painted while
     * the choice is open.
     *
     * @param non-empty-list<ChoiceOption> $options
     */
    public function choose(
        string $title,
        array $options,
        ?string $description = null,
    ): ?string
    {
        if ($this->choice instanceof DeferredFuture) {
            // One list at a time: a second one would take the place of the
            // first and leave whoever asked for it waiting for good.
            throw new LogicException('A choice is already open.');
        }

        $this->validateChoiceOptions($options);

        /** @var DeferredFuture<string|null> $choice */
        $choice = new DeferredFuture();
        $this->choice = $choice;
        $this->emptyComposer();
        $this->picker->open($title, $options, $description);
        $this->showPicker();
        $this->tui->setFocus($this->picker->focusable());
        $this->tui->requestRender();

        $chosen = $choice->getFuture()->await();

        if ($this->leaving) {
            $this->leaving = false;
            $this->tui->stop();
        }

        return $chosen;
    }

    /**
     * Rejects a malformed choice before the composer or Picker is touched.
     *
     * @param array<mixed> $options
     */
    private function validateChoiceOptions(array $options): void
    {
        if ($options === []) {
            throw new InvalidArgumentException(
                'A choice must offer at least one option.',
            );
        }

        if (!array_is_list($options)) {
            throw new InvalidArgumentException(
                'Choice options must be an ordered list.',
            );
        }

        $keys = [];

        foreach ($options as $option) {
            if (!$option instanceof ChoiceOption) {
                throw new InvalidArgumentException(
                    'Every choice option must be a ChoiceOption.',
                );
            }

            if (in_array($option->key, $keys, true)) {
                throw new InvalidArgumentException(
                    'Choice option keys must be unique.',
                );
            }

            $keys[] = $option->key;
        }
    }

    /**
     * Whether a person is choosing from a list rather than writing.
     */
    public function isChoosing(): bool
    {
        return $this->picker->isOpen();
    }

    public function acceptUserMessage(string $contents): void
    {
        $this->emptyComposer();
        $this->history->addMessage('❯', $contents, 'user');
    }

    public function beginAgentResponse(): ToolActivity
    {
        $this->activeAgentMessage = null;

        return $this->newToolActivity();
    }

    public function appendAgentText(string $chunk): void
    {
        if (!$this->activeAgentMessage instanceof HistoryEntry) {
            $this->activeAgentMessage = $this->history->addMessage(
                '●',
                '',
                'agent',
            );
        }

        $this->activeAgentMessage->appendText($chunk);
    }

    public function showEmptyResponse(): void
    {
        if (!$this->activeAgentMessage instanceof HistoryEntry) {
            $this->history->addMessage('●', '_Empty response._', 'agent');

            return;
        }

        $this->activeAgentMessage->setText('_Empty response._');
    }

    /**
     * Shows a line a Slash command said.
     */
    public function showNotice(string $text): void
    {
        $this->history->addMessage('·', $text, 'notice');
    }

    public function showError(string $message): void
    {
        $this->history->addMessage('Error', $message, 'error');
    }

    public function showUnknownSlashCommand(string $command): void
    {
        $this->showError('Unknown Slash command: ' . $command);
    }

    /**
     * The animation that says the Agent is busy.
     *
     * It owns its own line in the History, so the view has nothing to say
     * about it beyond handing it over.
     */
    public function workingIndicator(): WorkingIndicator
    {
        return $this->workingIndicator;
    }

    /**
     * Tells the composer a turn is in flight. `ready()` is the counterpart.
     */
    public function working(): void
    {
        $this->working = true;
        // A command the TUI would turn away mid-turn is not offered mid-turn.
        $this->suggestions->working();
        $this->showStatus();
    }

    /**
     * @param list<string> $messages
     */
    public function showQueuedMessages(array $messages): void
    {
        $this->emptyComposer();
        $this->queuedMessages->clear();

        if ($messages !== []) {
            $lines = [
                '● Messages to be submitted after the current turn',
            ];

            foreach ($messages as $message) {
                $message = DisplayableText::safe($message);
                $lines[] = '  ↳ ' . str_replace(
                    "\n",
                    "\n    ",
                    $message,
                );
            }

            $queued = new TextWidget(implode("\n", $lines));
            $queued->addStyleClass('queued-message');
            $this->queuedMessages->add($queued);
        }

        $this->tui->requestRender();
    }

    public function ready(): void
    {
        $this->working = false;
        $this->suggestions->ready();
        $this->showStatus();
        $this->tui->setFocus($this->editor);
        $this->history->followLatest();
    }

    public function scrollUp(): void
    {
        $this->history->scrollUp();
    }

    public function scrollDown(): void
    {
        $this->history->scrollDown();
    }

    private function build(string $titleText, string $subtitleText): void
    {
        $header = new ContainerWidget();
        $header->addStyleClass('header');
        $title = new TextWidget('✦ ' . $titleText);
        $title->addStyleClass('title');
        $subtitle = new TextWidget($subtitleText);
        $subtitle->addStyleClass('subtitle');
        $header->add($title);
        $header->add($subtitle);

        $this->showConversationControls();

        $this->tui->add($header);
        $this->tui->add($this->history->widget());
        $this->tui->add($this->queuedMessages);
        $this->tui->add($this->lowerPanel);
        $this->tui->setFocus($this->editor);
    }

    /**
     * Tells the Command suggestions what is being written now.
     */
    private function draftChanged(ChangeEvent $event): void
    {
        $this->suggestions->draftChanged($event->getValue());
        $this->showStatus();
        $this->tui->requestRender();
    }

    /**
     * Answers the keys that belong to the suggestions while they are on
     * screen, and leaves every other key to the composer.
     *
     * These are taken here, among the listeners that run before the widget
     * holding the focus, rather than by giving the list the focus: the
     * composer keeps it throughout, and a key is taken from it only where
     * the suggestions have an answer for it — Tab excepted, which is never
     * the composer's. ↑↓ cost the composer nothing meanwhile, a name being
     * written being one line by definition. Enter replaces a selectable
     * draft before the composer submits it through its usual path.
     */
    private function handleSuggestionKeys(InputEvent $event): void
    {
        if ($this->picker->isOpen()) {
            // The keys are the list's while a person is choosing, and there
            // are no suggestions on screen to answer them anyway.
            return;
        }

        $data = $event->getData();

        if ($this->keys->matches($data, 'suggestion-previous')) {
            $this->move($event, $this->suggestions->choosePrevious(...));

            return;
        }

        if ($this->keys->matches($data, 'suggestion-next')) {
            $this->move($event, $this->suggestions->chooseNext(...));

            return;
        }

        if ($this->keys->matches($data, 'suggestion-complete')) {
            // Tab is never the composer's: a tabulation in a draft is not
            // something anyone asked for, so where there is nothing to
            // complete it does nothing at all.
            $event->stopPropagation();
            $this->complete();

            return;
        }

        if ($this->keys->matches($data, 'suggestion-run')) {
            $chosen = $this->suggestions->chosenName();

            if ($chosen !== null) {
                // Leave Enter to the composer after replacing the prefix:
                // its ordinary submit event remains the one command parsing
                // and dispatch listen to.
                $this->writeDraft($chosen);
            }

            return;
        }

        if (
            $this->keys->matches($data, 'suggestion-close')
            && $this->suggestions->isOnScreen()
        ) {
            // The first Escape takes the band away and leaves the draft; the
            // next one reaches the composer and empties it, as it always has.
            // The line that says nothing matches is taken away too: it
            // covers the conversation like the list, whatever the other keys
            // have to say about it.
            $event->stopPropagation();
            $this->suggestions->dismiss();
            $this->showStatus();
            $this->tui->requestRender();
        }
    }

    /**
     * Moves through the list, if there is a list to move through.
     *
     * @param Closure(): bool $moved
     */
    private function move(InputEvent $event, Closure $moved): void
    {
        if (!$moved()) {
            return;
        }

        $event->stopPropagation();
        $this->tui->requestRender();
    }

    /**
     * Writes the chosen name in the composer, where there is one.
     *
     * The name is followed by a space, which closes the list by the rule
     * that opens it and leaves the cursor where the arguments are written.
     */
    private function complete(): void
    {
        $chosen = $this->suggestions->chosenName();

        if ($chosen === null) {
            return;
        }

        $this->writeDraft($chosen . ' ');
        $this->tui->requestRender();
    }

    /**
     * Puts the given text in the composer, telling the suggestions about it.
     *
     * Text written from here raises nothing the editor reports, so what is
     * being written is said out loud rather than waited for.
     */
    private function writeDraft(string $draft): void
    {
        $this->editor->writeDraft($draft);
        $this->suggestions->draftChanged($draft);
        $this->showStatus();
    }

    /**
     * Says on the status line which keys mean something now.
     *
     * There is one of these per writing state the TUI can be read in, and
     * the suggestions are the third: while there is a list to move through,
     * the line names the keys that move, complete and run. The line that
     * says nothing matches is not one of them, nothing there being
     * choosable. Choosing replaces this line with the Picker footer.
     */
    private function showStatus(): void
    {
        $this->status->setText(match (true) {
            $this->suggestions->isListOpen() => self::SUGGESTING_STATUS,
            $this->working => self::WORKING_STATUS,
            default => self::READY_STATUS,
        });
    }

    private function clearDraft(CancelEvent $event): void
    {
        $this->emptyComposer();
    }

    /**
     * Answers the waiting command with no choice at all.
     *
     * Nothing is waiting once the picker is closed, so this is also what a
     * person leaving the terminal mid-choice ends up saying.
     */
    private function abandon(): void
    {
        $this->closePicker(null);
    }

    /**
     * Leaves the picker, whatever came of it: the list goes, the keys and the
     * status line go back to the conversation, and the command that was
     * waiting is resumed with what a person decided.
     */
    private function closePicker(?string $key): void
    {
        if (!$this->choice instanceof DeferredFuture) {
            return;
        }

        $choice = $this->choice;
        // Let go of the choice before completing it: the command resumes
        // from there, and must not find the answer it has just been given
        // still standing open.
        $this->choice = null;
        $this->picker->close();
        $this->showConversationControls();
        $this->ready();
        $choice->complete($key);
    }

    /**
     * Replaces every writing control with the one focused choice panel.
     */
    private function showPicker(): void
    {
        $this->picker->widget()->setStyle(new Style(hidden: false));
        $this->conversationControls->setStyle(new Style(hidden: true));
    }

    /**
     * Restores the controls that belong to writing after a choice closes.
     */
    private function showConversationControls(): void
    {
        if ($this->conversationControls->all() === []) {
            $this->conversationControls->add($this->suggestions->widget());
            $this->conversationControls->add($this->composerRow);
            $this->conversationControls->add($this->status);
            $this->lowerPanel->add($this->picker->widget());
            $this->lowerPanel->add($this->conversationControls);
        }

        $this->picker->widget()->setStyle(new Style(hidden: true));
        $this->conversationControls->setStyle(new Style(hidden: false));
    }

    private function newToolActivity(): ToolActivity
    {
        return new ToolActivity($this->history);
    }

}
