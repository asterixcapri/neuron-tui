<?php

declare(strict_types=1);

namespace NeuronCli\Tui;

use Closure;
use NeuronCli\Session\Session;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * The list a person moves through while the TUI is in the Session picker.
 *
 * Arrow navigation and a bounded visible height are the select list's own;
 * what is added here is the translation between a Session and a line on
 * screen, and the filtering — both the typing the widget does not listen for
 * and the narrowing itself, because a line names its Session rather than
 * describing it. The picker holds the Sessions it was given and hands back
 * the one a person chose, so a key never travels as text. Whoever opens the
 * picker decides what focus and the composer do meanwhile.
 *
 * @internal
 */
final class SessionPicker
{
    private const int VISIBLE_SESSIONS = 8;

    /**
     * The width the list gives a label before cutting it silently, so a
     * title is shortened here instead, where the cut is marked.
     */
    private const int TITLE_WIDTH = 30;

    /**
     * What a handle starts with, so that it is a name of the picker's own
     * rather than a bare number a caller might read something into.
     */
    private const string HANDLE_PREFIX = 'session-';

    private const string INSTRUCTIONS =
        'Sessions · ↑↓ moves · type filters · Enter resumes · Escape cancels';

    private readonly ContainerWidget $widget;

    private readonly TextWidget $instructions;

    private readonly SelectListWidget $list;

    /**
     * The Sessions on offer, each under the handle its line carries.
     *
     * @var array<string, Session>
     */
    private array $offered = [];

    /**
     * Every line of the open picker, filtered or not, in the order the
     * provider listed them.
     *
     * @var list<array{value: string, label: string, description: string}>
     */
    private array $lines = [];

    /** @var list<array{value: string, label: string, description: string}> */
    private array $shown = [];

    private string $filter = '';

    private bool $open = false;

    /**
     * @param Closure(Session): void $chosen
     * @param Closure(): void $abandoned
     */
    public function __construct(
        private readonly Closure $chosen,
        private readonly Closure $abandoned,
    ) {
        $this->widget = new ContainerWidget();
        $this->widget->addStyleClass('session-picker');
        $this->instructions = new TextWidget(self::INSTRUCTIONS);
        $this->instructions->addStyleClass('session-picker-instructions');
        $this->list = new SelectListWidget([], self::VISIBLE_SESSIONS);
        $this->list->addStyleClass('session-list');
        $this->list->onInput($this->type(...));
    }

    public function widget(): ContainerWidget
    {
        return $this->widget;
    }

    /**
     * The widget the keys belong to while the picker is open.
     */
    public function focusable(): SelectListWidget
    {
        return $this->list;
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    /**
     * Shows the Sessions, in the order the provider listed them.
     *
     * @param list<Session> $sessions
     */
    public function open(array $sessions): void
    {
        $this->filter = '';
        $this->offered = [];
        $this->lines = [];

        foreach ($sessions as $place => $session) {
            $handle = self::HANDLE_PREFIX . $place;
            $this->offered[$handle] = $session;
            $this->lines[] = $this->line($handle, $session);
        }

        $this->shown = $this->lines;
        $this->list->setItems($this->shown);
        $this->instructions->setText(self::INSTRUCTIONS);
        $this->widget->clear();
        $this->widget->add($this->instructions);
        $this->widget->add($this->list);
        // Taking the list off screen detaches it, and a detached widget is
        // left without the listeners it was given: Enter and Escape would
        // reach a list with nobody listening for what they mean. So the
        // answers to them are given here, once per opening, after the list
        // is back on screen — which is also the only place a second opening
        // passes through. Typing is not a listener and survives the detach.
        $this->list->onSelect($this->choose(...));
        $this->list->onCancel($this->abandon(...));
        $this->open = true;
    }

    /**
     * Takes the list away, leaving nothing of the choice on screen and no
     * Session held past the moment a person could still choose it.
     */
    public function close(): void
    {
        $this->widget->clear();
        $this->offered = [];
        $this->lines = [];
        $this->shown = [];
        $this->open = false;
    }

    /**
     * The line standing for a Session, under a handle of the picker's own.
     *
     * The list reports a chosen item by its `value`, so the value has to say
     * which Session was chosen. It says it with a handle the picker minted
     * and can look up: the key stays inside the Session, which is what the
     * picker hands back, so no layer between here and the provider holds a
     * string it cannot interpret.
     *
     * @return array{value: string, label: string, description: string}
     */
    private function line(string $handle, Session $session): array
    {
        return [
            'value' => $handle,
            'label' => DisplayableText::preview(
                $session->title,
                self::TITLE_WIDTH,
            ),
            'description' => $session->lastUsedAt->format('Y-m-d H:i'),
        ];
    }

    /**
     * Narrows the list by what is being typed.
     *
     * Anything that is not text — an arrow, Enter, Escape — is left to the
     * list, which is the only thing that knows what to do with it.
     */
    private function type(string $data): bool
    {
        if ($data === "\x7f" || $data === "\x08") {
            $this->filterBy(mb_substr($this->filter, 0, -1));

            return true;
        }

        if ($data === '' || preg_match('/[\x00-\x1f\x7f]/', $data) === 1) {
            return false;
        }

        $this->filterBy($this->filter . $data);

        return true;
    }

    /**
     * Narrows to the Sessions whose title starts with what was typed.
     *
     * The list narrows on the value of a line, which names a Session rather
     * than describing it, so the narrowing happens here against the title. A
     * keystroke that narrows nothing leaves the list alone, so that the
     * Session a person moved to stays the one under the arrow.
     */
    private function filterBy(string $filter): void
    {
        $this->filter = $filter;
        $matching = array_values(array_filter(
            $this->lines,
            static fn (array $line): bool => str_starts_with(
                strtolower($line['label']),
                strtolower($filter),
            ),
        ));

        if ($matching !== $this->shown) {
            $this->shown = $matching;
            $this->list->setItems($matching);
        }

        $this->instructions->setText(
            $filter === ''
                ? self::INSTRUCTIONS
                : self::INSTRUCTIONS . "\nfilter: "
                    . DisplayableText::safe($filter),
        );
    }

    private function choose(SelectEvent $event): void
    {
        $chosen = $this->offered[$event->getValue()] ?? null;

        if (!$chosen instanceof Session) {
            // A value the picker did not put in the list names no Session,
            // so there is nothing to open and the list stays where it is.
            return;
        }

        ($this->chosen)($chosen);
    }

    private function abandon(CancelEvent $event): void
    {
        ($this->abandoned)();
    }
}
