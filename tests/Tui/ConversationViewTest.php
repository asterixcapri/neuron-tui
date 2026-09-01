<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Tui;

use InvalidArgumentException;
use LogicException;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronTui\Conversation\ChoiceOption;
use NeuronTui\Tui\ConversationView;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Revolt\EventLoop;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class ConversationViewTest extends TestCase
{
    public function testAChoiceMustOfferAtLeastOneOption(): void
    {
        $view = new ConversationView(
            new VirtualTerminal(rows: 24),
            'Neuron AI',
            'Conversation',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'A choice must offer at least one option.',
        );

        self::invokeChoiceWithMalformedOptions($view, []);
    }

    public function testAChoiceRejectsDuplicateKeysBeforeOpening(): void
    {
        $view = new ConversationView(
            new VirtualTerminal(rows: 24),
            'Neuron AI',
            'Conversation',
        );

        try {
            $view->choose('Models', [
                new ChoiceOption('same-key', 'Claude Haiku'),
                new ChoiceOption('same-key', 'Claude Opus'),
            ]);
            self::fail('Duplicate choice keys should be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                'Choice option keys must be unique.',
                $exception->getMessage(),
            );
        }

        self::assertFalse($view->isChoosing());
    }

    public function testAChoiceRejectsAnAssociativeOptionCollection(): void
    {
        $view = new ConversationView(
            new VirtualTerminal(rows: 24),
            'Neuron AI',
            'Conversation',
        );

        try {
            self::invokeChoiceWithMalformedOptions($view, [
                'haiku' => new ChoiceOption('haiku', 'Claude Haiku'),
            ]);
            self::fail('An associative choice collection should be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                'Choice options must be an ordered list.',
                $exception->getMessage(),
            );
        }

        self::assertFalse($view->isChoosing());
    }

    public function testAChoiceRejectsAlternativeOptionRepresentations(): void
    {
        $view = new ConversationView(
            new VirtualTerminal(rows: 24),
            'Neuron AI',
            'Conversation',
        );

        try {
            self::invokeChoiceWithMalformedOptions(
                $view,
                ['haiku' => 'Claude Haiku'],
            );
            self::fail('A key-to-label map should be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                'Choice options must be an ordered list.',
                $exception->getMessage(),
            );
        }

        self::assertFalse($view->isChoosing());
    }

    public function testAChoiceRejectsAnEmptyLabelBeforeOpening(): void
    {
        $view = new ConversationView(
            new VirtualTerminal(rows: 24),
            'Neuron AI',
            'Conversation',
        );

        try {
            $view->choose('Models', [new ChoiceOption('haiku', '')]);
            self::fail('Empty choice labels should be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                'Choice option labels must not be empty.',
                $exception->getMessage(),
            );
        }

        self::assertFalse($view->isChoosing());
    }

    public function testAChoiceRejectsAnEmptyPresentDetailBeforeOpening(): void
    {
        $view = new ConversationView(
            new VirtualTerminal(rows: 24),
            'Neuron AI',
            'Conversation',
        );

        try {
            $view->choose('Models', [
                new ChoiceOption('haiku', 'Claude Haiku', " \n "),
            ]);
            self::fail('Empty choice details should be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                'Choice option details must not be empty.',
                $exception->getMessage(),
            );
        }

        self::assertFalse($view->isChoosing());
    }

    public function testAChoiceRejectsAWhitespaceOnlyLabelBeforeOpening(): void
    {
        $view = new ConversationView(
            new VirtualTerminal(rows: 24),
            'Neuron AI',
            'Conversation',
        );

        try {
            $view->choose('Models', [new ChoiceOption('haiku', " \n\t ")]);
            self::fail('Whitespace-only choice labels should be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                'Choice option labels must not be empty.',
                $exception->getMessage(),
            );
        }

        self::assertFalse($view->isChoosing());
    }

    public function testAChoiceRejectsControlOnlyTextBeforeOpening(): void
    {
        $view = new ConversationView(
            new VirtualTerminal(rows: 24),
            'Neuron AI',
            'Conversation',
        );

        foreach ([
            static fn () => new ChoiceOption('haiku', "\x00\x07"),
            static fn () => new ChoiceOption(
                'haiku',
                'Claude Haiku',
                "\x00\x07",
            ),
        ] as $invalidOption) {
            try {
                $view->choose('Models', [$invalidOption()]);
                self::fail('Control-only choice text should be rejected.');
            } catch (InvalidArgumentException) {
                // The option rejected itself before choose could alter the UI.
            }

            self::assertFalse($view->isChoosing());
        }
    }

    public function testASecondChoiceCannotReplaceAnOpenChoice(): void
    {
        $firstResult = 'still waiting';
        $secondFailure = null;
        $view = new ConversationView(
            new VirtualTerminal(rows: 24),
            'Neuron AI',
            'Conversation',
        );

        EventLoop::queue(
            static function () use ($view, &$firstResult): void {
                $firstResult = $view->choose('Models', [
                    new ChoiceOption('haiku', 'Claude Haiku'),
                ]);
            },
        );
        EventLoop::delay(
            0.01,
            static function () use ($view, &$secondFailure): void {
                try {
                    $view->choose('Models', [
                        new ChoiceOption('opus', 'Claude Opus'),
                    ]);
                } catch (LogicException $exception) {
                    $secondFailure = $exception;
                }

                $view->stop();
            },
        );

        $view->run();

        self::assertInstanceOf(LogicException::class, $secondFailure);
        self::assertSame(
            'A choice is already open.',
            $secondFailure->getMessage(),
        );
        self::assertNull($firstResult);
    }

    public function testAHistoryCanBePaintedAtAnyMomentNotOnlyAtStartup(): void
    {
        $terminal = new VirtualTerminal(rows: 24);
        $view = new ConversationView($terminal, 'Neuron AI', 'Conversation');

        $view->showHistory([
            new UserMessage('What we discussed before'),
            new AssistantMessage('An answer from before'),
        ]);
        $view->showHistory([
            new UserMessage('What we discuss now'),
        ]);
        $view->paintPendingChanges();

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());

        self::assertStringContainsString('❯ What we discuss now', $display);
        self::assertStringNotContainsString(
            'What we discussed before',
            $display,
        );
        self::assertStringNotContainsString(
            'An answer from before',
            $display,
        );
    }

    /**
     * Calls the runtime boundary without asking static analysis to accept
     * deliberately malformed input.
     *
     * @param array<mixed> $options
     */
    private static function invokeChoiceWithMalformedOptions(
        ConversationView $view,
        array $options,
    ): void {
        (new ReflectionMethod($view, 'choose'))->invoke(
            $view,
            'Models',
            $options,
        );
    }
}
