<?php

declare(strict_types=1);

namespace NeuronCli;

use Amp\Future;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronCli\Tui\ConversationView;
use NeuronCli\Tui\DisplayableText;
use NeuronCli\Tui\WorkingIndicator;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Terminal\Terminal;
use Symfony\Component\Tui\Terminal\TerminalInterface;
use Throwable;

use function Amp\async;

final class NeuronCli
{
    private readonly TerminalInterface $terminal;

    private readonly ConversationView $view;

    private readonly WorkingIndicator $workingIndicator;

    /** @var Future<mixed>|null */
    private ?Future $response = null;

    private ?string $pendingInput = null;

    /** @var list<string> */
    private array $queuedInputs = [];

    public function __construct(
        private readonly Agent $agent,
        string $title = 'Neuron AI',
        string $subtitle = 'Agent conversation',
        ?TerminalInterface $terminal = null,
    ) {
        $this->terminal = $terminal ?? new Terminal();
        $this->view = new ConversationView(
            $this->terminal,
            $title,
            $subtitle,
        );
        $this->workingIndicator = $this->view->workingIndicator();
        $this->view->showExistingHistory(
            $this->agent->getChatHistory()->getMessages(),
        );
        $this->view->onSubmit($this->submit(...));
        $this->view->onInput($this->handleInput(...));
        $this->view->onTick($this->tick(...));
    }

    public function run(): void
    {
        if (
            $this->terminal instanceof Terminal
            && (
                !stream_isatty(STDIN)
                || !stream_isatty(STDOUT)
            )
        ) {
            throw new \RuntimeException(
                'Neuron CLI requires an interactive TTY.',
            );
        }

        $this->view->run();
    }

    private function submit(SubmitEvent $event): void
    {
        if ($event->isBlank()) {
            return;
        }

        $input = $event->getValue();

        if ($input === '/exit') {
            $this->view->stop();

            return;
        }

        if (str_starts_with($input, '/')) {
            $command = strtok($input, " \t\n");
            $this->view->showUnknownSlashCommand($command);

            return;
        }

        if (
            $this->response instanceof Future
            || $this->pendingInput !== null
        ) {
            $this->queuedInputs[] = $input;
            $this->view->showQueuedMessages($this->queuedInputs);

            return;
        }

        $this->view->acceptUserMessage($input);
        $this->startWorking();
        $this->pendingInput = $input;
    }

    private function respond(string $input): void
    {
        $tools = $this->view->beginAgentResponse();
        $contents = '';

        $events = $this->agent
            ->stream(new UserMessage($input))
            ->events();

        foreach ($events as $event) {
            if ($event instanceof ToolCallChunk) {
                $tools->start($event->tool);
                $this->view->paintPendingChanges();

                continue;
            }

            if ($event instanceof ToolResultChunk) {
                $this->workingIndicator->whilePaused(
                    microtime(true),
                    static function () use ($tools, $event): void {
                        $tools->finish($event->tool);
                    },
                );
                $this->view->paintPendingChanges();

                continue;
            }

            if (!$event instanceof TextChunk) {
                continue;
            }

            $this->workingIndicator->stop();
            $contents .= $event->content;
            $this->view->appendAgentText($event->content);
            $this->view->paintPendingChanges();
        }

        $visibleContents = DisplayableText::safe($contents);

        if (trim($visibleContents) === '' && !$tools->hasActivity()) {
            $this->workingIndicator->stop();
            $this->view->showEmptyResponse();
        }
    }

    private function tick(): bool
    {
        if ($this->pendingInput !== null) {
            $input = $this->pendingInput;
            $this->pendingInput = null;
            $this->response = async(function () use ($input): void {
                $this->respond($input);
            });

            return true;
        }

        if (!$this->response instanceof Future) {
            return false;
        }

        if (!$this->response->isComplete()) {
            $this->workingIndicator->advance(microtime(true));

            return true;
        }

        try {
            $this->response->await();
        } catch (WorkflowInterrupt $exception) {
            $this->view->showError(
                'Human-in-the-loop interruptions are not supported. '
                    . $exception->getMessage(),
            );
        } catch (Throwable $exception) {
            $this->view->showError(
                $exception::class . ': ' . $exception->getMessage(),
            );
        }

        $this->response = null;
        $this->stopWorking();

        if ($this->queuedInputs !== []) {
            $input = array_shift($this->queuedInputs);
            $this->view->showQueuedMessages($this->queuedInputs);
            $this->view->acceptUserMessage($input);
            $this->startWorking();
            $this->pendingInput = $input;

            return true;
        }

        return false;
    }

    private function startWorking(): void
    {
        $this->view->working();
        $this->workingIndicator->start(microtime(true));
    }

    private function stopWorking(): void
    {
        $this->workingIndicator->stop();
        $this->view->ready();
    }

    private function handleInput(InputEvent $event): void
    {
        $keys = new Keybindings([
            'quit' => [Key::ctrl('c')],
            'scroll-up' => [Key::PAGE_UP],
            'scroll-down' => [Key::PAGE_DOWN],
        ]);

        if ($keys->matches($event->getData(), 'quit')) {
            $event->stopPropagation();
            $this->view->stop();

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
