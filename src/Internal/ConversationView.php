<?php

declare(strict_types=1);

namespace NeuronCli\Internal;

use Closure;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ContentBlocks\VideoContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Terminal\TerminalInterface;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\EditorWidget;
use Symfony\Component\Tui\Widget\MarkdownWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Tui\Widget\Util\StringUtils;

/**
 * Owns the visual state and terminal rendering of a Conversation TUI.
 *
 * @internal
 */
final class ConversationView
{
    private const string READY_STATUS =
        'ready · Enter sends · Shift+Enter adds a line · /exit exits';

    private readonly Tui $tui;

    private readonly ContainerWidget $history;

    private readonly EditorWidget $editor;

    private readonly TextWidget $status;

    private int $historyItemCount = 0;

    private int $scrollOffset = 0;

    private ?MarkdownWidget $activeAgentMessage = null;

    private int $activeAgentMessageHeight = 0;

    public function __construct(
        private readonly TerminalInterface $terminal,
        string $title,
        string $subtitle,
    ) {
        $this->tui = new Tui(
            ConversationStyleSheet::create(),
            $this->terminal,
        );
        $this->history = new ContainerWidget();
        $this->history->addStyleClass('history');
        $this->history->expandVertically(true);
        $this->editor = new EditorWidget();
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

    /**
     * @param array<Message> $messages
     */
    public function showExistingHistory(array $messages): void
    {
        $tools = $this->newToolActivity();

        foreach ($messages as $message) {
            $role = $message->getRole();

            if (
                $role !== MessageRole::USER->value
                && $role !== MessageRole::ASSISTANT->value
            ) {
                continue;
            }

            if ($message instanceof ToolCallMessage) {
                $contents = self::messageContents($message);

                if ($contents !== '') {
                    $this->addMessage('●', $contents, 'agent');
                }

                foreach ($message->getTools() as $tool) {
                    $tools->start($tool);
                }
            } elseif ($message instanceof ToolResultMessage) {
                foreach ($message->getTools() as $tool) {
                    $tools->finish($tool);
                }
            } elseif ($role === MessageRole::USER->value) {
                $contents = self::messageContents($message);

                if ($contents !== '') {
                    $this->addMessage('❯', $contents, 'user');
                }
            } else {
                $contents = self::messageContents($message);

                if ($contents !== '') {
                    $this->addMessage('●', $contents, 'agent');
                }
            }
        }
    }

    public function showUserMessage(string $contents): void
    {
        $this->addMessage('❯', $contents, 'user');
    }

    public function beginAgentResponse(): ToolActivity
    {
        $this->activeAgentMessage = null;
        $this->activeAgentMessageHeight = 0;

        return $this->newToolActivity();
    }

    public function appendAgentText(string $chunk): void
    {
        if (!$this->activeAgentMessage instanceof MarkdownWidget) {
            $this->activeAgentMessage = $this->addMessage(
                '●',
                '',
                'agent',
            );
            $this->activeAgentMessageHeight = 1;
        }

        $this->activeAgentMessage->setText(
            $this->activeAgentMessage->getText() . $chunk,
        );
        $height = max(1, $this->markdownHeight(
            $this->activeAgentMessage,
            1,
        ));
        $this->historyHeightChanged(
            $height - $this->activeAgentMessageHeight,
        );
        $this->activeAgentMessageHeight = $height;
    }

    public function showEmptyResponse(): void
    {
        if (!$this->activeAgentMessage instanceof MarkdownWidget) {
            $this->addMessage('●', '_Empty response._', 'agent');

            return;
        }

        $this->activeAgentMessage->setText('_Empty response._');
        $height = max(1, $this->markdownHeight(
            $this->activeAgentMessage,
            1,
        ));
        $this->historyHeightChanged(
            $height - $this->activeAgentMessageHeight,
        );
        $this->activeAgentMessageHeight = $height;
    }

    public function showError(string $message): void
    {
        $this->addMessage('Error', $message, 'error');
    }

    public function showUnknownSlashCommand(string $command): void
    {
        $this->showError('Unknown Slash command: ' . $command);
    }

    public function startWorking(string $frame): void
    {
        $this->status->setText($frame . ' working…');
        $this->tui->setFocus(null);
        $this->followLatest();
    }

    public function updateWorkingFrame(string $frame): void
    {
        $this->status->setText($frame . ' working…');
        $this->tui->requestRender();
    }

    public function ready(): void
    {
        $this->status->setText(self::READY_STATUS);
        $this->tui->setFocus($this->editor);
        $this->followLatest();
    }

    public function scrollUp(): void
    {
        $this->scrollOffset += 5;
        $this->applyScrollOffset();
    }

    public function scrollDown(): void
    {
        $this->scrollOffset = max(0, $this->scrollOffset - 5);
        $this->applyScrollOffset();
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
        $this->tui->add($this->history);
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
        return new ToolActivity(
            max(1, $this->terminal->getColumns() - 2),
            $this->addToolActivity(...),
            $this->historyHeightChanged(...),
        );
    }

    private function addToolActivity(TextWidget $activity, int $height): void
    {
        $this->addHistoryWidget($activity, $height);
    }

    private function addMessage(
        string $speaker,
        string $contents,
        string $style,
    ): MarkdownWidget {
        $message = new ContainerWidget();
        $message->addStyleClass('message');
        $label = new TextWidget($speaker);
        $label->addStyleClass('speaker');
        $label->addStyleClass($style);
        $markdown = new MarkdownWidget($contents);
        $markdown->addStyleClass('message-content');
        $message->add($label);
        $message->add($markdown);
        $this->addHistoryWidget(
            $message,
            max(1, $this->markdownHeight(
                $markdown,
                mb_strwidth($speaker, 'UTF-8'),
            )),
        );

        return $markdown;
    }

    private function markdownHeight(
        MarkdownWidget $markdown,
        int $speakerWidth,
    ): int {
        $columns = max(
            1,
            $this->terminal->getColumns() - 3 - $speakerWidth,
        );

        return count($markdown->render(new RenderContext(
            $columns,
            PHP_INT_MAX,
        )));
    }

    private function addHistoryWidget(
        AbstractWidget $widget,
        int $height,
    ): void {
        $gap = $this->historyItemCount === 0 ? 0 : 1;
        $this->history->add($widget);
        $this->historyItemCount++;
        $this->historyHeightChanged($height + $gap);
    }

    private function historyHeightChanged(int $difference): void
    {
        if ($this->scrollOffset > 0 && $difference !== 0) {
            $this->scrollOffset = max(
                0,
                $this->scrollOffset + $difference,
            );
            $this->tui->setScrollOffset($this->scrollOffset);
        }

        $this->tui->requestRender();
    }

    private function followLatest(): void
    {
        if ($this->scrollOffset === 0) {
            $this->tui->setScrollOffset(0);
        }

        $this->tui->requestRender();
    }

    private function applyScrollOffset(): void
    {
        $this->tui->setScrollOffset($this->scrollOffset);
        $this->tui->requestRender();
    }

    private static function messageContents(Message $message): string
    {
        $contents = [];

        foreach ($message->getContentBlocks() as $block) {
            $content = match (true) {
                $block instanceof ReasoningContent => null,
                $block instanceof TextContent => $block->getContent(),
                $block instanceof ImageContent => '[Image]',
                $block instanceof FileContent => self::filePlaceholder($block),
                $block instanceof AudioContent => '[Audio]',
                $block instanceof VideoContent => '[Video]',
                default => null,
            };

            if ($content !== null && $content !== '') {
                $contents[] = $content;
            }
        }

        return implode("\n\n", $contents);
    }

    private static function filePlaceholder(FileContent $file): string
    {
        if ($file->filename === null) {
            return '[File]';
        }

        $filename = StringUtils::stripControlBytes(
            StringUtils::sanitizeUtf8($file->filename),
        );
        $filename = basename(str_replace('\\', '/', $filename));
        $filename = preg_replace('/\s+/u', ' ', trim($filename)) ?? '';
        $filename = mb_strimwidth(
            $filename,
            0,
            80,
            '…',
            'UTF-8',
        );

        return $filename === '' ? '[File]' : '[File: ' . $filename . ']';
    }
}
