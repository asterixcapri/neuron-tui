<?php

declare(strict_types=1);

namespace NeuronCli\Tui;

use Closure;
use NeuronCli\Session\SessionSummary;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * The list a person moves through while the TUI is in the Session picker.
 *
 * Arrow navigation, a bounded visible height and filtering are the select
 * list's own; what is added here is the typing that drives the filter — the
 * widget narrows on demand but does not listen for the characters — and the
 * translation between a Session and a line on screen. Whoever opens the
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

    private const string INSTRUCTIONS =
        'Sessions · ↑↓ moves · type filters · Enter resumes · Escape cancels';

    /**
     * The list reports a chosen item by its `value`, and filters on the same
     * string, so the value has to carry both what a person types against and
     * the key to open. A null byte keeps them apart: displayable text cannot
     * contain one, so the two never run together.
     */
    private const string KEY_SEPARATOR = "\x00";

    private readonly ContainerWidget $widget;

    private readonly TextWidget $instructions;

    private readonly SelectListWidget $sessions;

    private string $filter = '';

    private bool $open = false;

    /**
     * @param Closure(string): void $chosen
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
        $this->sessions = new SelectListWidget([], self::VISIBLE_SESSIONS);
        $this->sessions->addStyleClass('session-list');
        $this->sessions->onInput($this->type(...));
        $this->sessions->onSelect($this->choose(...));
        $this->sessions->onCancel($this->abandon(...));
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
        return $this->sessions;
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    /**
     * Shows the Sessions, in the order the store listed them.
     *
     * @param list<SessionSummary> $sessions
     */
    public function open(array $sessions): void
    {
        $this->filter = '';
        $this->sessions->setItems(array_map($this->line(...), $sessions));
        $this->sessions->setFilter('');
        $this->instructions->setText(self::INSTRUCTIONS);
        $this->widget->clear();
        $this->widget->add($this->instructions);
        $this->widget->add($this->sessions);
        $this->open = true;
    }

    /**
     * Takes the list away, leaving nothing of the choice on screen.
     */
    public function close(): void
    {
        $this->widget->clear();
        $this->open = false;
    }

    /**
     * @return array{value: string, label: string, description: string}
     */
    private function line(SessionSummary $session): array
    {
        $title = DisplayableText::preview($session->title, self::TITLE_WIDTH);

        return [
            'value' => $title . self::KEY_SEPARATOR . $session->key,
            'label' => $title,
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

    private function filterBy(string $filter): void
    {
        $this->filter = $filter;
        $this->sessions->setFilter($filter);
        $this->instructions->setText(
            $filter === ''
                ? self::INSTRUCTIONS
                : self::INSTRUCTIONS . "\nfilter: "
                    . DisplayableText::safe($filter),
        );
    }

    private function choose(SelectEvent $event): void
    {
        $chosen = explode(self::KEY_SEPARATOR, $event->getValue(), 2);

        if (!isset($chosen[1])) {
            // A value the picker did not put in the list names no Session,
            // so there is nothing to open and the list stays where it is.
            return;
        }

        ($this->chosen)($chosen[1]);
    }

    private function abandon(CancelEvent $event): void
    {
        ($this->abandoned)();
    }
}
