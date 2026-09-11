<?php

declare(strict_types=1);

namespace NeuronTui\Tests;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronInteraction\Command\CommandAdapterInterface;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronTui\Tui;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class SkillInvocationPresentationTest extends TestCase
{
    public function testALiveSkillInvocationIsCompactButReachesTheAgentUnchanged(): void
    {
        $expanded = self::invocation(
            'writing',
            '/skills/writing',
            'Prefer direct sentences.',
            'Write a short introduction.',
        );
        $provider = new FakeAIProvider(new AssistantMessage('Done.'));
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $terminal = new VirtualTerminal(rows: 30);

        EventLoop::queue(
            static fn () => $terminal->simulateInput("/writing Write a short introduction.\r"),
        );
        EventLoop::delay(
            0.15,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new Tui(
            $agent,
            terminal: $terminal,
            commands: new Commands(new ExpandedSkillCommand('writing', $expanded)),
        ))->run();

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());

        self::assertStringContainsString("❯ /writing", $display);
        self::assertMatchesRegularExpression(
            '/❯ \/writing[^\r\n]*\r\n[^\r\n]*\r\n[^\r\n]*Write a short introduction\./',
            $display,
        );
        self::assertStringContainsString('Write a short introduction.', $display);
        self::assertStringNotContainsString('Prefer direct sentences.', $display);
        self::assertStringNotContainsString('/skills/writing', $display);
        self::assertSame(
            $expanded,
            $provider->getRecorded()[0]->messages[0]->getContent(),
        );
        self::assertSame(
            $expanded,
            $agent->getChatHistory()->getMessages()[0]->getContent(),
        );
    }

    public function testALiveSkillInvocationWithoutArgumentsShowsOnlyTheCommand(): void
    {
        $expanded = self::invocation(
            'review',
            '/skills/review',
            'Inspect every change.',
        );
        $provider = new FakeAIProvider(new AssistantMessage('Done.'));
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $terminal = new VirtualTerminal(rows: 24);

        EventLoop::queue(
            static fn () => $terminal->simulateInput("/review\r"),
        );
        EventLoop::delay(
            0.15,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new Tui(
            $agent,
            terminal: $terminal,
            commands: new Commands(new ExpandedSkillCommand('review', $expanded)),
        ))->run();

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());

        self::assertStringContainsString('❯ /review', $display);
        self::assertStringNotContainsString('Inspect every change.', $display);
        self::assertStringNotContainsString('/skills/review', $display);
        self::assertSame(
            $expanded,
            $provider->getRecorded()[0]->messages[0]->getContent(),
        );
    }

    public function testLoadedHistoryUsesTheSameCompactPresentationForEveryInvocation(): void
    {
        $first = self::invocation(
            'writing',
            '/skills/writing',
            'First private instruction.',
            'Draft the opening.',
        );
        $second = self::invocation(
            'review',
            '/skills/review',
            'Second private instruction.',
        );
        $history = new LoadedSkillHistory([
            new UserMessage($first),
            new AssistantMessage('Draft complete.'),
            new UserMessage($second),
        ]);
        $agent = new Agent();
        $agent->setChatHistory($history);
        $terminal = new VirtualTerminal(rows: 40);

        EventLoop::delay(
            0.1,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new Tui($agent, terminal: $terminal))->run();

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());

        self::assertStringContainsString('❯ /writing', $display);
        self::assertStringContainsString('Draft the opening.', $display);
        self::assertStringContainsString('❯ /review', $display);
        self::assertStringNotContainsString('First private instruction.', $display);
        self::assertStringNotContainsString('Second private instruction.', $display);
        self::assertSame($first, $history->getMessages()[0]->getContent());
        self::assertSame($second, $history->getMessages()[2]->getContent());
    }

    public function testOrdinaryAndInexactSkillMarkupRemainFullyVisible(): void
    {
        $normal = 'Explain the deployment plan.';
        $partial = '<skill name="partial" location="/skills/partial">\nKeep this visible.';
        $embedded = "Before the envelope.\n"
            . self::invocation(
                'embedded',
                '/skills/embedded',
                'Embedded instructions stay visible.',
            )
            . "\nAfter the envelope.";
        $malformed = "<skill name=\"broken\" location=\"/skills/broken\">\n"
            . "Malformed instructions stay visible.\n"
            . '</wrong>';
        $agent = new Agent();
        $agent->setChatHistory(new LoadedSkillHistory([
            new UserMessage($normal),
            new UserMessage($partial),
            new UserMessage($embedded),
            new UserMessage($malformed),
        ]));
        $terminal = new VirtualTerminal(columns: 120, rows: 40);

        EventLoop::delay(
            0.1,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new Tui($agent, terminal: $terminal))->run();

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());

        self::assertStringContainsString($normal, $display);
        self::assertStringContainsString('Keep this visible.', $display);
        self::assertStringContainsString('Embedded instructions stay visible.', $display);
        self::assertStringContainsString('Malformed instructions stay visible.', $display);
    }

    public function testAnEnvelopeAccompaniedByAnotherContentBlockRemainsFullyVisible(): void
    {
        $expanded = self::invocation(
            'vision',
            '/skills/vision',
            'These instructions stay visible.',
        );
        $agent = new Agent();
        $agent->setChatHistory(new LoadedSkillHistory([
            new Message(MessageRole::USER, [
                new TextContent($expanded),
                new ImageContent('raw-image', SourceType::BASE64),
            ]),
        ]));
        $terminal = new VirtualTerminal(rows: 30);

        EventLoop::delay(
            0.1,
            static fn () => $terminal->simulateInput("\x03"),
        );

        (new Tui($agent, terminal: $terminal))->run();

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());

        self::assertStringContainsString('These instructions stay visible.', $display);
        self::assertStringContainsString('[Image]', $display);
    }

    private static function invocation(
        string $name,
        string $location,
        string $instructions,
        string $request = '',
    ): string {
        $message = "<skill name=\"{$name}\" location=\"{$location}\">\n"
            . "References are relative to {$location}.\n\n"
            . $instructions . "\n"
            . '</skill>';

        return $request === '' ? $message : $message . "\n\n" . $request;
    }
}

final readonly class ExpandedSkillCommand implements CommandInterface
{
    public function __construct(
        private string $name,
        private string $expanded,
    ) {
    }

    public function name(): string
    {
        return '/' . $this->name;
    }

    public function describe(): string
    {
        return 'Runs a Skill.';
    }

    /** @param CommandAdapterInterface<mixed> $adapter */
    public function run(CommandAdapterInterface $adapter, string $value): void
    {
        $adapter->promptAgent($this->expanded);
    }
}

final class LoadedSkillHistory extends InMemoryChatHistory
{
    /** @param list<Message> $messages */
    public function __construct(array $messages)
    {
        parent::__construct();
        $this->history = $messages;
    }
}
