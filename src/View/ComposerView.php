<?php

declare(strict_types=1);

namespace NeuronTui\View;

use Closure;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Style\Border;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/** @internal */
final class ComposerView extends ContainerWidget
{
    private readonly InputWidget $input;
    private readonly TextWidget $status;

    public function __construct()
    {
        $inputStyle = new Style(
            border: new Border(1, 0, 1, 0, color: Theme::BORDER),
            color: Theme::SECONDARY,
        );
        $prompt = (new Style(color: Theme::PROMPT))->apply('❯') . $inputStyle->getAnsiRestore() . ' ';
        $this->input = (new InputWidget())->setPrompt($prompt)->setStyle($inputStyle);
        $this->status = (new TextWidget('✻ Ready'))->setStyle(new Style(color: Theme::MUTED));
        $this->add($this->status)->add($this->input);
        $this->add((new TextWidget('Enter to send · Ctrl+C to exit', truncate: true))
            ->setStyle(new Style(color: Theme::MUTED)));
    }

    public function input(): InputWidget
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
        $this->input->setValue('');
        $this->status->setText('● Working…')->setStyle(new Style(color: Theme::ACCENT));
    }

    public function finishTurn(): void
    {
        $this->status->setText('✻ Ready')->setStyle(new Style(color: Theme::MUTED));
    }
}
