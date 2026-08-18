<?php

declare(strict_types=1);

namespace NeuronCli\Tui;

use Amp\DeferredFuture;
use Closure;
use LogicException;
use NeuronAI\Chat\Messages\Message;
use NeuronCli\History\EntryKind;
use NeuronCli\History\HistoryProjection;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Event\SubmitEvent;
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
        'ready · Enter sends · Shift+Enter adds a line · /exit exits';

    private const string WORKING_STATUS =
        'Enter queues · Shift+Enter adds a line';

    private const string CHOOSING_STATUS =
        'choosing · the composer takes no text until you choose';

    private readonly Tui $tui;

    private readonly HistoryPane $history;

    private readonly ContainerWidget $queuedMessages;

    private readonly ComposerEditor $editor;

    private readonly TextWidget $status;

    private readonly WorkingIndicator $workingIndicator;

    private readonly Picker $picker;

    private ?HistoryEntry $activeAgentMessage = null;

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

    public function __construct(
        private readonly TerminalInterface $terminal,
        string $title,
        string $subtitle,
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
        $this->status = new TextWidget(self::READY_STATUS);
        $this->status->addStyleClass('status');
        $this->picker = new Picker(
            $this->closePicker(...),
            $this->abandon(...),
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
        $this->editor->setText('');
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
     * @param array<string, string> $options key => label
     */
    public function choose(string $title, array $options): ?string
    {
        if ($this->choice instanceof DeferredFuture) {
            // One list at a time: a second one would take the place of the
            // first and leave whoever asked for it waiting for good.
            throw new LogicException('A choice is already open.');
        }

        /** @var DeferredFuture<string|null> $choice */
        $choice = new DeferredFuture();
        $this->choice = $choice;
        $this->emptyComposer();
        $this->picker->open($title, $options);
        $this->status->setText(self::CHOOSING_STATUS);
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
        $this->status->setText(self::WORKING_STATUS);
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
        $this->status->setText(self::READY_STATUS);
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

        $composer = new ContainerWidget();
        $composer->addStyleClass('composer-row');
        $prompt = new TextWidget('❯');
        $prompt->addStyleClass('composer-label');
        $composer->add($prompt);
        $composer->add($this->editor);

        $this->tui->add($header);
        $this->tui->add($this->history->widget());
        $this->tui->add($this->queuedMessages);
        $this->tui->add($this->picker->widget());
        $this->tui->add($composer);
        $this->tui->add($this->status);
        $this->tui->setFocus($this->editor);
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
        $this->ready();
        $choice->complete($key);
    }

    private function newToolActivity(): ToolActivity
    {
        return new ToolActivity($this->history);
    }

}
