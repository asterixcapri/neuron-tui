<?php

declare(strict_types=1);

namespace NeuronTui\View;

use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;
use Symfony\Component\Tui\Style\Padding;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Tui\Widget\Util\StringUtils;

use function implode;
use function is_array;
use function is_object;
use function json_encode;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_PARTIAL_OUTPUT_ON_ERROR;
use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/** @internal */
final class ToolView extends ContainerWidget
{
    public function __construct(private readonly ToolCall $tool)
    {
        $this->display();
    }

    public function complete(ToolCall $result): void
    {
        $output = $result->hasResult() ? $result->getResult() : null;
        $error = $output instanceof ToolOutput && $output->isError() ? $output->getText() : null;
        $this->display($error);
    }

    private function display(?string $error = null): void
    {
        $this->clear();
        $style = new Style(color: $error !== null ? Theme::ERROR : Theme::ACCENT);
        $symbol = $error !== null ? '! ' : '● ';
        $title = $style->apply($symbol . StringUtils::stripControlBytes($this->tool->getName()));
        $inputs = $this->tool->getInputs();
        $nested = false;
        foreach ($inputs as $value) {
            if (is_array($value) || is_object($value)) {
                $nested = true;
                break;
            }
        }
        $parameterStyle = new Style(color: Theme::SECONDARY);
        if (!$nested && $inputs !== []) {
            $parameters = [];
            foreach ($inputs as $name => $value) {
                $parameters[] = StringUtils::stripControlBytes($name) . ': ' . $this->encode($value);
            }
            $title .= $parameterStyle->apply('(' . implode(', ', $parameters) . ')');
        }
        $this->add(new TextWidget($title));
        if ($nested) {
            $this->add((new TextWidget($this->encode($inputs, pretty: true)))
                ->setStyle(new Style(padding: new Padding(0, 0, 0, 2), color: Theme::SECONDARY)));
        }
        if ($error !== null) {
            $this->add((new TextWidget('└ ' . StringUtils::stripControlBytes($error)))
                ->setStyle(new Style(padding: new Padding(0, 0, 0, 2), color: Theme::ERROR)));
        }
    }

    private function encode(mixed $value, bool $pretty = false): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | ($pretty ? JSON_PRETTY_PRINT : 0));

        return $encoded === false ? '[unavailable]' : StringUtils::stripControlBytes($encoded);
    }
}
