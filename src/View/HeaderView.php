<?php

declare(strict_types=1);

namespace NeuronTui\View;

use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Tui\Widget\Util\StringUtils;

use function trim;

/** @internal */
final class HeaderView extends ContainerWidget
{
    private readonly TextWidget $figlet;
    private readonly TextWidget $title;
    private readonly TextWidget $description;

    public function __construct()
    {
        $this->figlet = new TextWidget();
        $this->title = (new TextWidget('✳ Neuron TUI'))->setStyle(new Style(color: Theme::ACCENT, bold: true));
        $this->description = (new TextWidget('A conversation with your agent'))
            ->setStyle(new Style(color: Theme::MUTED));
        $this->add($this->figlet)->add($this->title)->add($this->description);
    }

    public function setFiglet(string $text, string $font = 'standard'): void
    {
        $text = StringUtils::stripControlBytes($text);
        $this->figlet->setText($text);
        $this->figlet->setStyle(
            (new Style(color: Theme::ACCENT, font: $font))->withPadding([0, 0, trim($text) === '' ? 0 : 1, 0]),
        );
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
