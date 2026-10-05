<?php

declare(strict_types=1);

namespace NeuronTui\View;

use Closure;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/** @internal */
final class ComposerView extends ContainerWidget
{
    private readonly ComposerInputView $input;
    private readonly TextWidget $status;
    private readonly WorkingView $working;

    public function __construct()
    {
        $this->input = new ComposerInputView();
        $this->status = (new TextWidget('✻ Ready'))->setStyle(new Style(color: Theme::MUTED));
        $this->working = (new WorkingView())->setStyle(new Style(hidden: true));
        $this->add($this->status)->add($this->working)->add($this->input);
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

    public function beginTurn(): void
    {
        $this->input->setText('');
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
