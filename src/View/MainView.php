<?php

declare(strict_types=1);

namespace NeuronTui\View;

use Closure;
use LogicException;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\ToolCall;
use NeuronInteraction\Command\CommandInput;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Event\SelectionRequest;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Throwable;

use function array_map;
use function array_merge;
use function array_shift;
use function count;
use function trim;

/** @internal */
final class MainView extends ContainerWidget
{
    private readonly HistoryView $history;
    private readonly HeaderView $header;
    private readonly ComposerView $composer;
    private readonly Keybindings $keys;

    /** @var (Closure(string|CommandInput): void)|null */
    private ?Closure $onInput = null;

    /** @var (Closure(): iterable<Message>)|null */
    private ?Closure $onHistorySync = null;

    /** @var (Closure(): bool)|(Closure(): void)|null */
    private ?Closure $onEscape = null;

    private bool $busy = false;
    private bool $stopped = false;

    /** @var list<string> */
    private array $pending = [];

    /** @var list<SelectionRequest> */
    private array $selections = [];

    /** @var list<CommandInput> */
    private array $selectionResponses = [];

    public function __construct()
    {
        $this->history = new HistoryView();
        $this->header = new HeaderView();
        $this->composer = new ComposerView();
        $this->keys = new Keybindings([
            'stop' => [Key::ESCAPE],
            'quit' => ['ctrl+c'],
            'previous' => [Key::UP],
            'next' => [Key::DOWN],
            'confirm' => [Key::ENTER],
        ]);
        $this->setStyle(new Style(gap: 1));
        $this->add($this->header)->add($this->history)->add($this->composer);
        $this->composer->onSubmit($this->submit(...));
    }

    public function setTitle(string $title): void
    {
        $this->header->setTitle($title);
        $this->getContext()?->requestRender();
    }

    public function setDescription(string $description): void
    {
        $this->header->setDescription($description);
        $this->getContext()?->requestRender();
    }

    public function setFiglet(string $text, string $font = 'standard'): void
    {
        $this->header->setFiglet($text, $font);
        $this->getContext()?->requestRender();
    }

    public function syncHistory(): void
    {
        $this->history->load($this->onHistorySync === null ? [] : ($this->onHistorySync)());
    }

    public function focus(): void
    {
        $this->getContext()?->getFocusManager()->setFocus($this->composer->focusedInput());
    }

    public function handleInput(InputEvent $event): void
    {
        $data = $event->getData();
        if ($this->keys->matches($data, 'quit')) {
            $event->stopPropagation();
            $this->stop();
        } elseif ($this->selections !== []) {
            $event->stopPropagation();
            if ($this->keys->matches($data, 'previous')) {
                $this->composer->moveSelection(-1);
            } elseif ($this->keys->matches($data, 'next')) {
                $this->composer->moveSelection(1);
            } elseif ($this->keys->matches($data, 'confirm')) {
                $input = $this->composer->selectedInput();
                if ($input !== null) {
                    $this->resolveSelection($input);
                }
            } elseif ($this->keys->matches($data, 'stop')) {
                $this->resolveSelection(null);
            } else {
                $this->composer->handleSelectionInput($data);
            }
            $this->getContext()?->requestRender();
        } elseif ($this->composer->handleCommandInput($data)) {
            $event->stopPropagation();
        } elseif ($this->keys->matches($data, 'stop') && $this->busy) {
            $this->onEscape?->__invoke();
            $event->stopPropagation();
        }
    }

    private function submit(SubmitEvent $event): void
    {
        if ($event->isBlank() || $this->stopped || $this->onInput === null) {
            return;
        }
        $prompt = trim($event->getValue());
        $this->composer->input()->setText('');
        if ($this->busy) {
            $this->pending[] = $prompt;
            $this->updatePendingMessages();
            $this->getContext()?->requestRender();
            return;
        }
        $this->beginTurn($prompt);
    }

    private function beginTurn(string|CommandInput $prompt): void
    {
        $this->busy = true;
        $this->composer->beginTurn();
        $this->history->beginTurn(self::inputText($prompt));
        $this->getContext()?->requestRender();

        try {
            $this->onInput?->__invoke($prompt);
        } catch (Throwable $error) {
            $this->notify($error->getMessage(), MessageKind::Error);
            $this->finishTurn();
        }
    }

    public function stop(): void
    {
        $this->stopped = true;
        $this->pending = [];
        $this->selections = [];
        $this->selectionResponses = [];
        $this->composer->showSelection(null);
        $this->updatePendingMessages();
        $this->getContext()?->stop();
    }

    /** @param Closure(): list<CommandInterface> $commands */
    public function onCommands(Closure $commands): void
    {
        $this->composer->onCommands($commands);
    }

    /** @param Closure(string|CommandInput): void $listener */
    public function onInput(Closure $listener): void
    {
        $this->onInput = $listener;
    }

    /** @param Closure(): iterable<Message> $listener */
    public function onHistorySync(Closure $listener): void
    {
        $this->onHistorySync = $listener;
    }

    /** @param Closure(): (list<UserMessage>|null) $listener */
    public function onInputHistory(Closure $listener): void
    {
        $this->composer->onInputHistory($listener);
    }

    /** @param (Closure(): bool)|(Closure(): void) $listener */
    public function onEscape(Closure $listener): void
    {
        $this->onEscape = $listener;
    }

    public function appendResponse(string $text): void
    {
        if (!$this->busy) {
            throw new LogicException('No turn is in progress.');
        }
        $this->history->appendResponse($text);
        $this->getContext()?->requestRender();
    }

    public function notify(string $text, MessageKind $kind = MessageKind::Notice): void
    {
        if (!$this->busy) {
            throw new LogicException('No turn is in progress.');
        }
        $this->history->notify($text, $kind);
        $this->getContext()?->requestRender();
    }

    public function showToolCall(ToolCall $tool): void
    {
        if (!$this->busy) {
            throw new LogicException('No turn is in progress.');
        }
        $this->history->showToolCall($tool);
        $this->getContext()?->requestRender();
    }

    public function showToolResult(ToolCall $tool): void
    {
        if (!$this->busy) {
            throw new LogicException('No turn is in progress.');
        }
        $this->history->showToolResult($tool);
        $this->getContext()?->requestRender();
    }

    public function showSelection(SelectionRequest $request): void
    {
        if ($this->stopped) {
            return;
        }
        $this->selections[] = $request;
        if (count($this->selections) === 1) {
            $this->composer->showSelection($request);
            $this->focus();
        }
        $this->getContext()?->requestRender();
    }

    private function resolveSelection(?CommandInput $input): void
    {
        array_shift($this->selections);
        $this->composer->showSelection($this->selections[0] ?? null);
        $this->focus();
        if ($input !== null) {
            $this->selectionResponses[] = $input;
        }
        $this->updatePendingMessages();
        $this->startNextTurn();
    }

    private function startNextTurn(): void
    {
        if ($this->busy || $this->stopped) {
            return;
        }
        if ($this->selectionResponses !== []) {
            $input = array_shift($this->selectionResponses);
        } elseif ($this->selections === [] && $this->pending !== []) {
            $input = array_shift($this->pending);
        } else {
            return;
        }
        $this->updatePendingMessages();
        $this->beginTurn($input);
    }

    private function updatePendingMessages(): void
    {
        $this->composer->setPendingMessages(array_merge(
            array_map(self::inputText(...), $this->selectionResponses),
            $this->pending,
        ));
    }

    private static function inputText(string|CommandInput $input): string
    {
        return $input instanceof CommandInput
            ? $input->identifier . ($input->value === '' ? '' : ' ' . $input->value)
            : $input;
    }

    public function finishTurn(): void
    {
        $this->history->finishTurn();
        $this->composer->finishTurn();
        $this->busy = false;
        $this->startNextTurn();
        $this->focus();
        $this->getContext()?->requestRender();
    }

}
