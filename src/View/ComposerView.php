<?php

declare(strict_types=1);

namespace NeuronTui\View;

use Closure;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronInteraction\Command\CommandInput;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Event\SelectionRequest;
use Symfony\Component\Tui\Event\ChangeEvent;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Tui\Widget\Util\StringUtils;

use function array_slice;
use function count;
use function implode;
use function str_replace;
use function trim;

/** @internal */
final class ComposerView extends ContainerWidget
{
    private readonly ComposerInputView $input;
    private readonly CommandMenuView $commands;
    private readonly SelectionView $selection;
    private readonly TextWidget $hint;
    private readonly Keybindings $keys;
    private readonly TextWidget $status;
    private readonly WorkingView $working;
    private readonly ContainerWidget $queue;
    private readonly TextWidget $queueTitle;
    private readonly TextWidget $queueMessages;

    public function __construct()
    {
        $this->input = new ComposerInputView();
        $this->commands = new CommandMenuView();
        $this->selection = new SelectionView();
        $this->keys = new Keybindings([
            'previous' => [Key::UP],
            'next' => [Key::DOWN],
            'complete' => [Key::TAB],
            'execute' => [Key::ENTER],
            'dismiss' => [Key::ESCAPE],
        ]);
        $this->input->onChange(fn(ChangeEvent $event) => $this->updateCommands());
        $this->input->onRecall($this->dismissCommands(...));
        $this->status = (new TextWidget('✻ Ready'))->setStyle(new Style(color: Theme::MUTED));
        $this->working = (new WorkingView())->setStyle(new Style(hidden: true));
        $this->queueTitle = (new TextWidget(truncate: true))->setStyle(new Style(color: Theme::TEXT));
        $this->queueMessages = (new TextWidget(truncate: true))->setStyle(new Style(color: Theme::MUTED));
        $this->queue = (new ContainerWidget())->setStyle((new Style(hidden: true))->withPadding([1, 0]));
        $this->queue->add($this->queueTitle)->add($this->queueMessages);
        $this->add($this->status)->add($this->working)->add($this->queue)
            ->add($this->commands)->add($this->selection)->add($this->input);
        $this->hint = (new TextWidget('Enter send · Shift+Enter newline · Ctrl+C exit', truncate: true))
            ->setStyle(new Style(color: Theme::MUTED));
        $this->add($this->hint);
    }

    public function input(): ComposerInputView
    {
        return $this->input;
    }

    public function showSelection(?SelectionRequest $request): void
    {
        $this->selection->show($request);
        $hidden = $request !== null;
        $this->input->setStyle(new Style(color: Theme::SECONDARY, hidden: $hidden));
        $this->dismissCommands();
    }

    public function focusedInput(): ComposerInputView|InputWidget
    {
        return $this->selection->getStyle()?->getHidden() === false ? $this->selection->search() : $this->input;
    }

    public function handleSelectionInput(string $data): void
    {
        $this->selection->handleInput($data);
        $this->updateHint();
    }

    public function moveSelection(int $direction): void
    {
        $this->selection->move($direction);
    }

    public function selectedInput(): ?CommandInput
    {
        return $this->selection->selectedInput();
    }

    /** @param Closure(): list<CommandInterface> $commands */
    public function onCommands(Closure $commands): void
    {
        $this->commands->onCommands($commands);
        $this->updateCommands();
    }

    private function updateCommands(): void
    {
        $this->commands->update($this->input->getText());
        $this->updateStatus();
    }

    private function dismissCommands(): void
    {
        $this->commands->dismiss();
        $this->updateStatus();
    }

    private function updateStatus(): void
    {
        $hidden = $this->working->getStyle()?->getHidden() === false || $this->commands->selectedName() !== null;
        $this->status->setStyle(new Style(color: Theme::MUTED, hidden: $hidden));
        $this->updateHint();
    }

    private function updateHint(): void
    {
        if ($this->selection->getStyle()?->getHidden() === false) {
            $this->hint->setText($this->selection->selectedInput() === null
                ? 'Type to search · Esc cancel · Ctrl+C exit'
                : '↑/↓ choose · Enter confirm · Type to search · Esc cancel · Ctrl+C exit');
            return;
        }

        $isWorking = $this->working->isRunning();
        if ($this->commands->selectedName() !== null) {
            $this->hint->setText($isWorking
                ? '↑/↓ choose · Tab complete · Enter queue · Esc close · Ctrl+C exit'
                : '↑/↓ choose · Tab complete · Enter run · Esc close · Ctrl+C exit');
            return;
        }

        $this->hint->setText($isWorking
            ? 'Enter queue · Shift+Enter newline · Ctrl+C exit'
            : 'Enter send · Shift+Enter newline · Ctrl+C exit');
    }

    /** @param Closure(): (list<UserMessage>|null) $history */
    public function onInputHistory(Closure $history): void
    {
        $this->input->onInputHistory(static function () use ($history): ?array {
            $messages = $history();
            if ($messages === null) {
                return null;
            }
            $entries = [];
            foreach ($messages as $message) {
                $text = $message->getContent();
                if ($text !== null && trim($text) !== '') {
                    $entries[] = $text;
                }
            }
            return $entries;
        });
    }

    public function handleCommandInput(string $data): bool
    {
        $name = $this->commands->selectedName();
        if ($name === null) {
            return false;
        }
        if ($this->keys->matches($data, 'previous')) {
            $this->commands->move(-1);
        } elseif ($this->keys->matches($data, 'next')) {
            $this->commands->move(1);
        } elseif ($this->keys->matches($data, 'dismiss')) {
            $this->dismissCommands();
        } elseif ($this->keys->matches($data, 'complete')) {
            $this->input->setText($name . ' ');
            $this->input->handleInput("\x1b[F");
        } elseif ($this->keys->matches($data, 'execute')) {
            $this->input->setText($name);
            $this->dismissCommands();
            $this->input->handleInput($data);
        } else {
            return false;
        }
        $this->getContext()?->requestRender();
        return true;
    }

    /** @param Closure(SubmitEvent): void $listener */
    public function onSubmit(Closure $listener): void
    {
        $this->input->onSubmit($listener);
    }

    /** @param list<string> $messages */
    public function setPendingMessages(array $messages): void
    {
        $total = count($messages);
        $this->queueTitle->setText("• Messages queued for the next turns ({$total})");
        $lines = [];
        foreach (array_slice($messages, 0, 3) as $message) {
            $preview = str_replace(["\r\n", "\r", "\n", "\t"], ' ', StringUtils::stripControlBytes($message));
            $lines[] = '  ↳ ' . $preview;
        }
        if ($total > 3) {
            $lines[] = '  … +' . ($total - 3) . ' more';
        }
        $this->queueMessages->setText(implode("\n", $lines));
        $this->queue->setStyle((new Style(hidden: $total === 0))->withPadding([1, 0]));
    }

    public function beginTurn(): void
    {
        $this->working->setStyle(new Style(hidden: false));
        $this->working->start();
        $this->updateStatus();
    }

    public function finishTurn(): void
    {
        $this->working->stop();
        $this->working->setStyle(new Style(hidden: true));
        $this->updateStatus();
    }
}
