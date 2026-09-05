<?php

declare(strict_types=1);

namespace NeuronTui\Conversation;

use NeuronInteraction\Command\Commands;
use NeuronInteraction\InputHistory\InputHistory;
use NeuronInteraction\Session\Sessions;
use NeuronTui\Tui\ConversationView;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;

/**
 * Interprets human input and keeps submission, recall, and draft editing together.
 *
 * @internal
 */
final class ConversationInput
{
    public function __construct(
        private readonly ConversationView $view,
        private readonly InputHistory $inputHistory,
        private readonly ConversationRuntime $runtime,
        private readonly Commands $commands,
        private readonly Sessions $sessions,
    ) {
    }

    public function submit(SubmitEvent $event): void
    {
        if ($this->runtime->isStopped()) {
            return;
        }

        $this->inputHistory->leave();

        if ($event->isBlank()) {
            return;
        }

        $this->inputHistory->record($event->getValue());
        $submission = Submission::interpret($event->getValue());

        if ($submission instanceof CommandInput) {
            $this->commands->run(
                $submission->name,
                $submission->arguments,
                new TuiAdapter($this->runtime, $this->view, $this->commands, $this->sessions),
            );

            return;
        }

        $this->runtime->send($submission);
    }

    public function draftChanged(): void
    {
        $this->inputHistory->leave();
    }

    public function handleInput(InputEvent $event): void
    {
        $keys = new Keybindings([
            'quit' => [Key::ctrl('c')],
            'recall-older-input' => [Key::UP],
            'recall-newer-input' => [Key::DOWN],
            'scroll-up' => [Key::PAGE_UP],
            'scroll-down' => [Key::PAGE_DOWN],
        ]);

        if ($keys->matches($event->getData(), 'quit')) {
            $event->stopPropagation();
            $this->runtime->stop();

            return;
        }

        // While a person is choosing from a list, the list owns the keys
        // that move through it, page keys included.
        if ($this->view->isChoosing()) {
            return;
        }

        if ($keys->matches($event->getData(), 'recall-older-input')) {
            if (
                $this->inputHistory->isNavigating()
                || $this->view->isComposerEmpty()
            ) {
                $input = $this->inputHistory->older();

                if ($input !== null) {
                    $event->stopPropagation();
                    $this->view->recallInput($input);
                }
            }

            return;
        }

        if (
            $keys->matches($event->getData(), 'recall-newer-input')
            && $this->inputHistory->isNavigating()
        ) {
            $input = $this->inputHistory->newer();

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
