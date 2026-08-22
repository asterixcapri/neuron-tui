<?php

declare(strict_types=1);

namespace NeuronCli\Tui;

use Symfony\Component\Tui\Style\Border;
use Symfony\Component\Tui\Style\Direction;
use Symfony\Component\Tui\Style\Padding;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Style\VerticalAlign;

/**
 * @internal
 */
final class ConversationStyleSheet
{
    public static function create(): StyleSheet
    {
        return new StyleSheet([
            ':root' => new Style(gap: 1),
            '.header' => new Style(
                border: new Border(0, 0, 1, 0, 'normal', 'gray'),
                padding: new Padding(0, 1, 1, 1),
            ),
            '.title' => new Style(color: 'magenta', bold: true),
            '.subtitle' => new Style(color: 'gray', dim: true),
            '.history' => new Style(
                gap: 1,
                padding: Padding::xy(1),
            ),
            '.message' => new Style(
                direction: Direction::Horizontal,
                gap: 1,
            ),
            '.user-message' => new Style(background: '#343434'),
            '.speaker' => new Style(flex: 0),
            '.message-content' => new Style(flex: 1),
            '.composer-row' => new Style(
                border: new Border(1, 0, 1, 0, 'normal', 'gray'),
                direction: Direction::Horizontal,
                gap: 1,
                verticalAlign: VerticalAlign::Center,
            ),
            '.composer-label' => new Style(
                color: 'magenta',
                bold: true,
                flex: 0,
            ),
            '.composer' => new Style(color: 'white', flex: 1),
            '.status' => new Style(color: 'gray', dim: true),
            '.conversation-controls' => new Style(gap: 1),
            '.user' => new Style(color: 'magenta', bold: true),
            '.agent' => new Style(color: 'magenta', bold: true),
            '.loading' => new Style(color: 'gray', dim: true),
            '.queued-message' => new Style(
                color: 'gray',
                padding: Padding::xy(1),
            ),
            '.picker' => new Style(
                border: new Border(1, 0, 0, 0, 'normal', 'gray'),
                gap: 1,
                padding: new Padding(1, 1, 0, 1),
            ),
            '.picker-heading' => new Style(color: 'magenta', bold: true),
            '.picker-description' => new Style(color: 'gray'),
            '.picker-search' => new Style(color: 'white'),
            '.picker-instructions' => new Style(
                color: 'gray',
                dim: true,
            ),
            '.picker-list::selected' => new Style(color: 'magenta'),
            '.picker-list::detail' => new Style(color: 'gray'),
            '.picker-list::no-match' => new Style(color: 'gray', dim: true),
            '.picker-list::scroll-info' => new Style(
                color: 'gray',
                dim: true,
            ),
            '.suggestions' => new Style(padding: Padding::xy(1)),
            '.suggestions-list::selected' => new Style(color: 'magenta'),
            '.suggestions-list::scroll-info' => new Style(
                color: 'gray',
                dim: true,
            ),
            '.suggestions-empty' => new Style(color: 'gray', dim: true),
            '.notice' => new Style(color: 'cyan'),
            '.tool' => new Style(color: 'cyan', dim: true),
            '.error' => new Style(color: 'red', bold: true),
        ]);
    }
}
