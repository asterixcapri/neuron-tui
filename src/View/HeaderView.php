<?php

declare(strict_types=1);

namespace NeuronTui\View;

use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/** @internal */
final class HeaderView extends ContainerWidget
{
    public function __construct()
    {
        $this->add((new TextWidget('✳ Neuron TUI'))->setStyle(new Style(color: '#d99a70', bold: true)));
        $this->add((new TextWidget('A conversation with your agent'))->setStyle(new Style(color: '#9a9086')));
    }
}
