<?php

declare(strict_types=1);

namespace NeuronCli\Tui;

use Closure;
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

    private readonly Tui $tui;

    private readonly HistoryPane $history;

    private readonly ContainerWidget $queuedMessages;

    private readonly ComposerEditor $editor;

    private readonly TextWidget $status;

    private ?HistoryEntry $activeAgentMessage = null;

    private ?HistoryEntry $loading = null;

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
        $this->queuedMessages = new ContainerWidget();
        $this->queuedMessages->addStyleClass('queued-messages');
        $this->editor = new ComposerEditor();
        $this->editor->addStyleClass('composer');
        $this->editor->setMinVisibleLines(1);
        $this->editor->setMaxVisibleLines(5);
        $this->editor->onCancel($this->clearDraft(...));
        $this->status = new TextWidget(self::READY_STATUS);
        $this->status->addStyleClass('status');

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
        $this->history->clear();
        $this->activeAgentMessage = null;
        $this->loading = null;

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

    public function acceptUserMessage(string $contents): void
    {
        $this->editor->setText('');
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

    public function showError(string $message): void
    {
        $this->history->addMessage('Error', $message, 'error');
    }

    public function showUnknownSlashCommand(string $command): void
    {
        $this->showError('Unknown Slash command: ' . $command);
    }

    public function startWorking(string $frame, int $elapsedSeconds): void
    {
        $this->status->setText(self::WORKING_STATUS);
        $this->loading = $this->history->addNote(
            self::workingText($frame, $elapsedSeconds),
            'loading',
        );
        $this->history->followLatest();
    }

    public function updateWorkingFrame(
        string $frame,
        int $elapsedSeconds,
    ): void {
        if (!$this->loading instanceof HistoryEntry) {
            return;
        }

        $this->loading->setText(
            self::workingText($frame, $elapsedSeconds),
        );
    }

    /**
     * @param list<string> $messages
     */
    public function showQueuedMessages(array $messages): void
    {
        $this->editor->setText('');
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

    public function stopWorking(): void
    {
        if (!$this->loading instanceof HistoryEntry) {
            return;
        }

        $this->history->remove($this->loading);
        $this->loading = null;
    }

    public function ready(): void
    {
        $this->stopWorking();
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
        $this->tui->add($composer);
        $this->tui->add($this->status);
        $this->tui->setFocus($this->editor);
    }

    private function clearDraft(CancelEvent $event): void
    {
        $this->editor->setText('');
    }

    private function newToolActivity(): ToolActivity
    {
        return new ToolActivity($this->history);
    }

    private static function workingText(
        string $frame,
        int $elapsedSeconds,
    ): string {
        return $frame
            . ' Working ('
            . $elapsedSeconds
            . 's)';
    }
}
