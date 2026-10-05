<?php

declare(strict_types=1);

namespace NeuronTui\Input;

use NeuronInteraction\Command\CommandInput;
use NeuronInteraction\InputHistory\InputHistory;
use NeuronTui\Turn\TurnScheduler;
use NeuronTui\View\ConversationView;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Throwable;

/**
 * Interprets human input and keeps submission, recall, and draft editing together.
 *
 * @internal
 */
final class InputHandler
{
    private readonly InputHistoryNavigation $navigation;

    public function __construct(
        private readonly ConversationView $view,
        private readonly InputHistory $inputHistory,
        private readonly TurnScheduler $scheduler,
    ) {
        $this->navigation = new InputHistoryNavigation($inputHistory);
    }

    public function handleSubmit(SubmitEvent $event): void
    {
        if ($this->scheduler->isStopped()) {
            return;
        }

        $this->navigation->leave();

        if ($event->isBlank() && !$this->view->composerHasAttachments()) {
            return;
        }

        $original = $this->view->composerMessage();
        $this->inputHistory->append($original);
        $submission = CommandInput::parse($event->getValue());

        if ($submission instanceof CommandInput) {
            $this->scheduler->submitCommand($submission);

            return;
        }

        try {
            $this->scheduler->enqueueMessage($original);
        } catch (Throwable $exception) {
            $this->view->showError($exception->getMessage());

            return;
        }

    }

    public function handleDraftChange(): void
    {
        $this->navigation->leave();
    }

    public function handleInput(InputEvent $event): void
    {
        $keys = new Keybindings([
            'quit' => [Key::ctrl('c')],
            'interrupt-turn' => [Key::ESCAPE],
            'recall-older-input' => [Key::UP],
            'recall-newer-input' => [Key::DOWN],
            'scroll-up' => [Key::PAGE_UP],
            'scroll-down' => [Key::PAGE_DOWN],
        ]);

        if ($keys->matches($event->getData(), 'quit')) {
            $event->stopPropagation();
            $this->scheduler->stop();

            return;
        }

        // While a person is choosing from a list, the list owns the keys
        // that move through it, page keys included.
        if ($this->view->isChoosing()) {
            return;
        }

        if (
            $keys->matches($event->getData(), 'interrupt-turn')
            && !$this->view->hasCommandSuggestions()
            && $this->scheduler->isBusy()
            && $this->scheduler->supportsResponseStop()
        ) {
            $event->stopPropagation();
            $this->scheduler->requestInterruption();

            return;
        }

        if ($keys->matches($event->getData(), 'recall-older-input')) {
            if (
                $this->navigation->isNavigating()
                || $this->view->isComposerEmpty()
            ) {
                $input = $this->navigation->older($this->view->composerMessage());

                if ($input !== null) {
                    $event->stopPropagation();
                    $this->view->recallInput($input);
                }
            }

            return;
        }

        if (
            $keys->matches($event->getData(), 'recall-newer-input')
            && $this->navigation->isNavigating()
        ) {
            $input = $this->navigation->newer();

            if ($input !== null) {
                $event->stopPropagation();
                $this->view->recallInput($input);
            }

            return;
        }

        if ($keys->matches($event->getData(), 'scroll-up')) {
            $event->stopPropagation();
            $this->view->scrollUp();

            return;
        }

        if ($keys->matches($event->getData(), 'scroll-down')) {
            $event->stopPropagation();
            $this->view->scrollDown();
        }
    }
}
