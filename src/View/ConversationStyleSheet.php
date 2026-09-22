<?php

declare(strict_types=1);

namespace NeuronTui\View;

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
    private const string ACCENT_COLOR = 'magenta';

    private const string PRIMARY_TEXT_COLOR = 'white';

    private const string SECONDARY_TEXT_COLOR = '#808080';

    private const string BORDER_COLOR = 'gray';

    private const string USER_MESSAGE_BACKGROUND = '#343434';

    private const string TOOL_ACTIVITY_COLOR = 'cyan';

    private const string WARNING_COLOR = 'yellow';

    private const string ERROR_COLOR = 'red';

    public static function create(): StyleSheet
    {
        return new StyleSheet([
            ':root' => new Style(gap: 1),
            '.header' => new Style(
                border: new Border(0, 0, 1, 0, 'normal', self::BORDER_COLOR),
                padding: new Padding(0, 1, 1, 0),
            ),
            '.figlet' => new Style(
                padding: new Padding(0, 0, 1, 0),
                color: self::ACCENT_COLOR,
                bold: true,
            ),
            '.title' => new Style(color: self::ACCENT_COLOR, bold: true),
            '.subtitle' => new Style(color: self::SECONDARY_TEXT_COLOR),
            '.history' => new Style(
                gap: 1,
                padding: new Padding(0, 1, 0, 0),
            ),
            '.message' => new Style(
                direction: Direction::Horizontal,
                gap: 1,
            ),
            '.user-message' => new Style(background: self::USER_MESSAGE_BACKGROUND),
            '.speaker' => new Style(flex: 0),
            '.message-content' => new Style(flex: 1),
            '.composer-row' => new Style(
                border: new Border(1, 0, 1, 0, 'normal', self::BORDER_COLOR),
                direction: Direction::Horizontal,
                gap: 1,
                verticalAlign: VerticalAlign::Top,
            ),
            '.composer-label' => new Style(
                color: self::ACCENT_COLOR,
                bold: true,
                flex: 0,
            ),
            '.composer' => new Style(color: self::PRIMARY_TEXT_COLOR, flex: 1),
            '.status' => new Style(color: self::SECONDARY_TEXT_COLOR),
            '.conversation-controls' => new Style(gap: 1),
            '.user' => new Style(color: self::ACCENT_COLOR, bold: true),
            '.agent' => new Style(color: self::ACCENT_COLOR, bold: true),
            '.loading' => new Style(color: self::SECONDARY_TEXT_COLOR),
            '.queued-message' => new Style(
                color: self::SECONDARY_TEXT_COLOR,
                padding: new Padding(0, 1, 0, 0),
            ),
            '.picker' => new Style(
                border: new Border(1, 0, 0, 0, 'normal', self::BORDER_COLOR),
                gap: 1,
                padding: new Padding(1, 1, 0, 0),
            ),
            '.picker-heading' => new Style(color: self::ACCENT_COLOR, bold: true),
            '.picker-description' => new Style(color: self::SECONDARY_TEXT_COLOR),
            '.picker-search' => new Style(color: self::PRIMARY_TEXT_COLOR),
            '.picker-instructions' => new Style(
                color: self::SECONDARY_TEXT_COLOR,
            ),
            '.picker-list::selected' => new Style(color: self::ACCENT_COLOR),
            '.picker-list::detail' => new Style(color: self::SECONDARY_TEXT_COLOR),
            '.picker-list::no-match' => new Style(color: self::SECONDARY_TEXT_COLOR),
            '.picker-list::scroll-info' => new Style(
                color: self::SECONDARY_TEXT_COLOR,
            ),
            '.suggestions' => new Style(padding: new Padding(0, 1, 0, 0)),
            '.suggestions-list::selected' => new Style(color: self::ACCENT_COLOR),
            '.suggestions-list::scroll-info' => new Style(
                color: self::SECONDARY_TEXT_COLOR,
            ),
            '.suggestions-empty' => new Style(color: self::SECONDARY_TEXT_COLOR),
            '.notice' => new Style(color: self::SECONDARY_TEXT_COLOR),
            '.event-muted' => new Style(color: self::SECONDARY_TEXT_COLOR),
            '.tool' => new Style(color: self::TOOL_ACTIVITY_COLOR, dim: true),
            '.warning' => new Style(color: self::WARNING_COLOR, bold: true),
            '.error' => new Style(color: self::ERROR_COLOR, bold: true),
        ]);
    }
}
