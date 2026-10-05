<?php

declare(strict_types=1);

namespace NeuronTui\View;

use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Tui\Widget\Util\StringUtils;

/** @internal */
final class HeaderView extends ContainerWidget
{
    private readonly TextWidget $title;
    private readonly TextWidget $description;

    public function __construct()
    {
        $this->title = (new TextWidget('✳ Neuron TUI'))->setStyle(new Style(color: Theme::ACCENT, bold: true));
        $this->description = (new TextWidget('A conversation with your agent'))->setStyle(new Style(color: Theme::MUTED));
        $this->add($this->title)->add($this->description);
    }

    public function setTitle(string $title): void
    {
        $this->title->setText('✳ ' . StringUtils::stripControlBytes($title));
    }

    public function setDescription(string $description): void
    {
        $this->description->setText(StringUtils::stripControlBytes($description));
    }
}
