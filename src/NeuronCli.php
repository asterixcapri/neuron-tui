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
use NeuronCli\Internal\ConversationView;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Terminal\Terminal;
use Symfony\Component\Tui\Terminal\TerminalInterface;
use Symfony\Component\Tui\Widget\Util\StringUtils;
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

    private int $workingFrame = 0;

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
        if (
            $this->response instanceof Future
            || $this->pendingInput !== null
            || $event->isBlank()
        ) {
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

        $this->view->acceptUserMessage($input);
        $this->startWorking();
        $this->pendingInput = $input;
    }

    private function respond(string $input): void
    {
        $tools = $this->view->beginAgentResponse();
        $contents = '';

        foreach (
            $this->agent->stream(new UserMessage($input))->events()
            as $event
        ) {
            if ($event instanceof ToolCallChunk) {
                $this->view->stopWorking();
                $tools->start($event->tool);

                continue;
            }

            if ($event instanceof ToolResultChunk) {
                $this->view->stopWorking();
                $tools->finish($event->tool);

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

        $visibleContents = StringUtils::stripControlBytes(
            StringUtils::sanitizeUtf8($contents),
        );

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

        return false;
    }

    private function startWorking(): void
    {
        $this->workingFrame = 0;
        $this->lastAnimationAt = microtime(true);
        $this->view->startWorking(
            self::WORKING_FRAMES[$this->workingFrame],
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
        );
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
