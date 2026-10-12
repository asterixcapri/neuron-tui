<?php

declare(strict_types=1);

namespace NeuronTui\View;

use Closure;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Tui\Widget\Util\StringUtils;

use function array_slice;
use function count;
use function implode;
use function str_replace;

/** @internal */
final class ComposerView extends ContainerWidget
{
    private readonly ComposerInputView $input;
    private readonly TextWidget $status;
    private readonly WorkingView $working;
    private readonly ContainerWidget $queue;
    private readonly TextWidget $queueTitle;
    private readonly TextWidget $queueMessages;

    public function __construct()
    {
        $this->input = new ComposerInputView();
        $this->status = (new TextWidget('✻ Ready'))->setStyle(new Style(color: Theme::MUTED));
        $this->working = (new WorkingView())->setStyle(new Style(hidden: true));
        $this->queueTitle = (new TextWidget(truncate: true))->setStyle(new Style(color: Theme::TEXT));
        $this->queueMessages = (new TextWidget(truncate: true))->setStyle(new Style(color: Theme::MUTED));
        $this->queue = (new ContainerWidget())->setStyle((new Style(hidden: true))->withPadding([1, 0]));
        $this->queue->add($this->queueTitle)->add($this->queueMessages);
        $this->add($this->status)->add($this->working)->add($this->queue)->add($this->input);
        $this->add((new TextWidget('Enter send · Shift+Enter newline · Ctrl+C exit', truncate: true))
            ->setStyle(new Style(color: Theme::MUTED)));
    }

    public function input(): ComposerInputView
    {
        return $this->input;
    }

    /** @param Closure(SubmitEvent): void $listener */
    public function onSubmit(Closure $listener): void
    {
        $this->input->onSubmit($listener);
    }

    /** @param list<string> $messages */
    public function setPendingMessages(array $messages): void
    {
        $total = count($messages);
        $this->queueTitle->setText("• Messages queued for the next turns ({$total})");
        $lines = [];
        foreach (array_slice($messages, 0, 3) as $message) {
            $preview = str_replace(["\r\n", "\r", "\n", "\t"], ' ', StringUtils::stripControlBytes($message));
            $lines[] = '  ↳ ' . $preview;
        }
        if ($total > 3) {
            $lines[] = '  … +' . ($total - 3) . ' more';
        }
        $this->queueMessages->setText(implode("\n", $lines));
        $this->queue->setStyle((new Style(hidden: $total === 0))->withPadding([1, 0]));
    }

    public function beginTurn(): void
    {
        $this->status->setStyle(new Style(color: Theme::MUTED, hidden: true));
        $this->working->setStyle(new Style(hidden: false));
        $this->working->start();
    }

    public function finishTurn(): void
    {
        $this->working->stop();
        $this->working->setStyle(new Style(hidden: true));
        $this->status->setStyle(new Style(color: Theme::MUTED, hidden: false));
    }
}
