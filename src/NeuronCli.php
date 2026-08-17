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
    private const array WORKING_FRAMES = ['✶', '✸', '✹', '✺', '✹', '✷'];

    private readonly TerminalInterface $terminal;

    private readonly ConversationView $view;

    /** @var Future<mixed>|null */
    private ?Future $response = null;

    private ?string $pendingInput = null;

    /** @var list<string> */
    private array $queuedInputs = [];

    private int $workingFrame = 0;

    private float $workingStartedAt = 0.0;

    private float $lastAnimationAt = 0.0;

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
        $this->view->showHistory(
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
                $this->view->stopWorking();
                $tools->finish($event->tool);
                $this->resumeWorking();
                $this->view->paintPendingChanges();

                continue;
            }

            if (!$event instanceof TextChunk) {
                continue;
            }

            $this->view->stopWorking();
            $contents .= $event->content;
            $this->view->appendAgentText($event->content);
            $this->view->paintPendingChanges();
        }

        $visibleContents = DisplayableText::safe($contents);

        if (trim($visibleContents) === '' && !$tools->hasActivity()) {
            $this->view->stopWorking();
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
            $this->animateWorking();

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
        $this->view->ready();

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
        $this->workingFrame = 0;
        $this->workingStartedAt = microtime(true);
        $this->lastAnimationAt = $this->workingStartedAt;
        $this->view->startWorking(
            self::WORKING_FRAMES[$this->workingFrame],
            0,
        );
    }

    private function resumeWorking(): void
    {
        $this->view->startWorking(
            self::WORKING_FRAMES[$this->workingFrame],
            $this->elapsedWorkingSeconds(),
        );
    }

    private function animateWorking(): void
    {
        $now = microtime(true);

        if ($now - $this->lastAnimationAt < 0.08) {
            return;
        }

        $this->workingFrame = ($this->workingFrame + 1)
            % count(self::WORKING_FRAMES);
        $this->lastAnimationAt = $now;
        $this->view->updateWorkingFrame(
            self::WORKING_FRAMES[$this->workingFrame],
            $this->elapsedWorkingSeconds(),
        );
    }

    private function elapsedWorkingSeconds(): int
    {
        return (int) floor(microtime(true) - $this->workingStartedAt);
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
