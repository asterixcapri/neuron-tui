<?php

declare(strict_types=1);

namespace NeuronTui\Tests;

use Generator;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Testing\FakeAIProvider;
use NeuronInteraction\Command\CommandAdapterInterface;
use NeuronInteraction\Command\CommandInterface;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\ResumeCommand;
use NeuronInteraction\Command\Selection;
use NeuronInteraction\Command\SelectionOption;
use NeuronInteraction\InputHistory\InputHistory;
use NeuronInteraction\Message\UserMessageProcessorInterface;
use NeuronInteraction\Message\UserMessageProcessors;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronTui\Tests\History\SessionHistory;
use NeuronTui\Tui;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use RuntimeException;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

use function count;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function substr;

final class UserMessageProcessorTest extends TestCase
{
    private const string IMAGE = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    public function testDisplayProjectionIsPaintedBeforePreparationWithoutChangingSubmittedInput(): void
    {
        $terminal = new VirtualTerminal(rows: 30);
        $provider = new FakeAIProvider(new AssistantMessage('Reply.'));
        $processor = $this->createMock(UserMessageProcessorInterface::class);
        $processor->expects(self::atLeastOnce())->method('forDisplay')->willReturnCallback(
            static function (UserMessage $message): UserMessage {
                self::assertSame('Original request', $message->getContent());
                $display = clone $message;
                $display->setContents('Public preview');

                return $display;
            },
        );
        $processor->expects(self::once())->method('forAgent')->willReturnCallback(
            static function (UserMessage $message) use ($terminal, $provider): UserMessage {
                self::assertStringContainsString('❯ Public preview', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                self::assertSame('Original request', $message->getContent());
                self::assertSame([], $provider->getRecorded());
                $prepared = clone $message;
                $prepared->setContents('Private instructions');

                return $prepared;
            },
        );
        $tui = Tui::make((new Agent())->setAiProvider($provider))
            ->setSessionStore(new SessionStore(new InMemoryStorage(), 'local'))
            ->setUserMessageProcessors($processor);
        EventLoop::queue(static fn() => $terminal->simulateInput("Original request\r"));
        EventLoop::delay(0.15, static fn() => $terminal->simulateInput("\x03"));

        $tui
            ->setTerminal($terminal)
            ->run();

        self::assertSame('Private instructions', $provider->getRecorded()[0]->messages[0]->getContent());
        self::assertStringNotContainsString('Private instructions', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testSingleAndArrayRegistrationsComposeWithoutChangingInputHistory(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Reply.'));
        $agent = (new Agent())->setThreadId('test-thread');
        $agent->setAiProvider($provider);
        $terminal = new VirtualTerminal(rows: 30);
        $inputHistory = new InputHistory(new InMemoryStorage());
        $store = new SessionStore(new InMemoryStorage(), 'local');
        $session = $store->create();
        $tui = Tui::make($agent)
            ->setSessionStore($store)
            ->setSession($session)
            ->setUserMessageProcessors((new UserMessageProcessors())->addProcessor([
                new EnvelopeProcessor('A'),
                new EnvelopeProcessor('B'),
                new EnvelopeProcessor('C'),
            ]))
            ->setTerminal($terminal)
            ->setInputHistory($inputHistory);

        EventLoop::queue(static fn() => $terminal->simulateInput("Hello\r"));
        EventLoop::delay(0.15, static fn() => $terminal->simulateInput("\x03"));
        $tui->run();

        self::assertSame('C[B[A[Hello]]]', $provider->getRecorded()[0]->messages[0]->getContent());
        self::assertSame('C[B[A[Hello]]]', $session->getMessages()[0]->getContent());
        self::assertSame('Hello', $inputHistory->older()?->getContent());
        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString('❯ Hello', $display);
        self::assertStringNotContainsString('C[B[A[Hello]]]', $display);
    }

    public function testLoadedUserMessagesUseTheSamePresentationButAssistantMessagesDoNot(): void
    {
        $store = new SessionStore(new InMemoryStorage(), 'test-user');
        $session = $store->create();
        $agent = $session->bindToAgent(new Agent());
        $agent->getChatHistory()->addMessage(new UserMessage('B[A[Earlier]]'));
        $agent->getChatHistory()->addMessage(new AssistantMessage('B[A[Reply]]'));
        $terminal = new VirtualTerminal(rows: 30);
        EventLoop::delay(0.05, static fn() => $terminal->simulateInput("\x03"));

        $tui = Tui::make($agent)
            ->setSessionStore($store)
            ->setSession($session)
            ->setUserMessageProcessors((new UserMessageProcessors())->addProcessor([
                new EnvelopeProcessor('A'),
                new EnvelopeProcessor('B'),
            ]));
        $tui->setTerminal($terminal);
        $tui->run();

        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString('❯ Earlier', $display);
        self::assertStringContainsString('B[A[Reply]]', $display);
        self::assertSame('B[A[Earlier]]', $session->getMessages()[0]->getContent());
    }

    public function testCommandPromptsUseTheSameSubmissionAndDisplayTheirPreview(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Done.'));
        $agent = (new Agent())->setThreadId('test-thread');
        $agent->setAiProvider($provider);
        $command = new class implements CommandInterface {
            public function name(): string
            {
                return '/prepared';
            }
            public function describe(): string
            {
                return 'Send a prepared prompt.';
            }
            public function run(CommandAdapterInterface $adapter, string $value): void
            {
                $adapter->promptAgent(new UserMessage('Command prompt'));
            }
        };
        $terminal = new VirtualTerminal(rows: 30);
        EventLoop::queue(static fn() => $terminal->simulateInput("/prepared\r"));
        EventLoop::delay(0.15, static fn() => $terminal->simulateInput("\x03"));

        Tui::make($agent)
            ->setSessionStore(new SessionStore(new InMemoryStorage(), 'local'))
            ->setUserMessageProcessors((new UserMessageProcessors())->addProcessor(new EnvelopeProcessor('A')))
            ->setTerminal($terminal)
            ->setCommands((new Commands())->addCommand($command))
            ->run();

        self::assertSame('A[Command prompt]', $provider->getRecorded()[0]->messages[0]->getContent());
        self::assertStringContainsString('❯ Command prompt', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testPreparationFailureLeavesTheDraftAndDoesNotContactTheProvider(): void
    {
        $provider = new FakeAIProvider();
        $agent = (new Agent())->setThreadId('test-thread');
        $agent->setAiProvider($provider);
        $processor = new class implements UserMessageProcessorInterface {
            public function forAgent(UserMessage $input): UserMessage
            {
                throw new RuntimeException('Cannot prepare message.');
            }
            public function forDisplay(UserMessage $content): UserMessage
            {
                return clone $content;
            }
        };
        $terminal = new VirtualTerminal(rows: 30);
        EventLoop::queue(static fn() => $terminal->simulateInput("Keep my draft\r"));
        EventLoop::delay(0.1, static fn() => $terminal->simulateInput("\x03"));

        Tui::make($agent)
            ->setSessionStore(new SessionStore(new InMemoryStorage(), 'local'))
            ->setUserMessageProcessors((new UserMessageProcessors())->addProcessor($processor))
            ->setTerminal($terminal)
            ->run();

        self::assertSame([], $provider->getRecorded());
        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString('Cannot prepare message.', $display);
        self::assertStringContainsString('❯ Keep my draft', $display);
    }

    public function testEmptyPreparedTextKeepsTheDraftAndOriginalRecallWithoutReservingATurn(): void
    {
        $processor = new class implements UserMessageProcessorInterface {
            public function forAgent(UserMessage $input): UserMessage
            {
                return new UserMessage(' ');
            }
            public function forDisplay(UserMessage $content): UserMessage
            {
                return clone $content;
            }
        };
        $provider = new FakeAIProvider();
        $tui = Tui::make((new Agent())->setAiProvider($provider))
            ->setSessionStore(new SessionStore(new InMemoryStorage(), 'local'))
            ->setUserMessageProcessors($processor);
        $inputs = new InputHistory(new InMemoryStorage());
        $terminal = new VirtualTerminal(rows: 30);
        EventLoop::queue(static fn() => $terminal->simulateInput("Keep original draft\r"));
        EventLoop::delay(0.1, static fn() => $terminal->simulateInput("\x03"));
        $tui
            ->setTerminal($terminal)
            ->setInputHistory($inputs)
            ->run();
        self::assertSame([], $provider->getRecorded());
        self::assertSame('Keep original draft', $inputs->older(new UserMessage(''))?->getContent());
        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString('The prepared user message is empty.', $display);
        self::assertStringContainsString('❯ Keep original draft', $display);
    }

    public function testQueuedMessagesArePreparedOnceAndDisplayedWithoutTheirEnvelope(): void
    {
        $provider = new class (new AssistantMessage('One.'), new AssistantMessage('Two.')) extends FakeAIProvider {
            protected function streamChunks(Message $response): Generator
            {
                \Amp\delay(0.15);
                yield new TextChunk('reply', $response->getContent() ?? '');

                return new ProviderResponse(message: $response);
            }
        };
        $agent = (new Agent())->setThreadId('test-thread');
        $agent->setAiProvider($provider);
        $terminal = new VirtualTerminal(rows: 30);
        $queued = '';
        EventLoop::queue(static fn() => $terminal->simulateInput("First\r"));
        EventLoop::delay(0.04, static fn() => $terminal->simulateInput("Second\r"));
        EventLoop::delay(0.08, static function () use ($terminal, &$queued): void {
            $queued = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        });
        EventLoop::delay(0.4, static fn() => $terminal->simulateInput("\x03"));

        Tui::make($agent)
            ->setSessionStore(new SessionStore(new InMemoryStorage(), 'local'))
            ->setUserMessageProcessors((new UserMessageProcessors())->addProcessor(new EnvelopeProcessor('A')))
            ->setTerminal($terminal)
            ->run();

        self::assertStringContainsString('↳ Second', $queued);
        self::assertStringNotContainsString('A[Second]', $queued);
        self::assertCount(2, $provider->getRecorded());
        self::assertSame('A[Second]', $provider->getRecorded()[1]->messages[2]->getContent());
    }

    public function testStoredSessionTitlesAreDisplayedAndSearchableIncludingRenamedResumeCommands(): void
    {
        foreach (['/resume', '/continue'] as $name) {
            $store = new SessionStore(new InMemoryStorage(), 'local');
            $session = $store->create();
            $titleMessage = new UserMessage('B[A[stored-payload]]');
            $titleMessage->setMetadata(['title-source' => 'original']);
            SessionHistory::of($session)->addMessage($titleMessage);
            $session->setTitle('Readable session');
            for ($index = 0; $index < 5; ++$index) {
                SessionHistory::of($store->create())->addMessage(new UserMessage('Other session ' . $index));
            }
            $agent = (new Agent())->setThreadId('test-thread');
            $terminal = new VirtualTerminal(rows: 30);
            $processor = $this->createMock(UserMessageProcessorInterface::class);
            $processor->expects(self::never())->method('forAgent');
            $processor->method('forDisplay')->willReturnCallback(
                static function (UserMessage $message): UserMessage {
                    $result = clone $message;
                    if ($message->getContent() === 'stored-payload') {
                        self::assertSame('original', $message->getMetadata('title-source'));
                        $result->setContents('Readable session');
                    }

                    return $result;
                },
            );
            $display = '';
            $tui = Tui::make($agent)
                ->setSessionStore($store)
                ->setUserMessageProcessors((new UserMessageProcessors())->addProcessor([$processor, new EnvelopeProcessor('A'), new EnvelopeProcessor('B')]))
                ->setTerminal($terminal)
                ->setCommands((new Commands())->addCommand(new ResumeCommand($name)));
            EventLoop::queue(static fn() => $terminal->simulateInput($name . "\r"));
            EventLoop::delay(0.05, static fn() => $terminal->simulateInput('Readable'));
            EventLoop::delay(0.08, static function () use ($terminal, &$display): void {
                $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                $terminal->simulateInput("\r");
            });
            EventLoop::delay(0.1, static fn() => $terminal->simulateInput("\x03"));

            $tui->run();

            self::assertStringContainsString('Readable session', $display);
            self::assertStringNotContainsString('stored-payload', $display);
            self::assertSame('B[A[stored-payload]]', $session->getMessages()[0]->getContent());
            self::assertSame('B[A[stored-payload]]', $store->get($session->getKey())?->getMessages()[0]->getContent());
        }
    }

    public function testCustomCommandPickerLabelsAndValuesArePreserved(): void
    {
        $command = new class implements CommandInterface {
            public ?string $chosen = null;

            public function name(): string
            {
                return '/custom';
            }
            public function describe(): string
            {
                return 'Choose an option.';
            }
            public function run(CommandAdapterInterface $adapter, string $value): void
            {
                if ($value !== '') {
                    $this->chosen = $value;

                    return;
                }

                $adapter->requestSelection(new Selection($this->name(), 'Custom options', [
                    new SelectionOption('opaque-key', 'A[Readable label]'),
                    new SelectionOption('other-key', 'Ordinary label'),
                ]));
            }
        };
        $terminal = new VirtualTerminal(rows: 30);
        $display = '';
        EventLoop::queue(static fn() => $terminal->simulateInput("/custom\r"));
        EventLoop::delay(0.05, static function () use ($terminal, &$display): void {
            $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
            $terminal->simulateInput("\r");
        });
        EventLoop::delay(0.1, static fn() => $terminal->simulateInput("\x03"));

        Tui::make((new Agent())->setThreadId('test-thread'))
            ->setSessionStore(new SessionStore(new InMemoryStorage(), 'local'))
            ->setUserMessageProcessors((new UserMessageProcessors())->addProcessor(new EnvelopeProcessor('A')))
            ->setTerminal($terminal)
            ->setCommands((new Commands())->addCommand($command))
            ->run();

        self::assertStringContainsString('Readable label', $display);
        self::assertStringContainsString('Ordinary label', $display);
        self::assertStringContainsString('A[Readable label]', $display);
        self::assertSame('opaque-key', $command->chosen);
    }

    public function testCommandMessagesKeepTheirImagesAndMetadataThroughTheQueue(): void
    {
        $first = new UserMessage(new ImageContent(self::IMAGE, SourceType::BASE64, 'image/png'));
        $second = new UserMessage(new ImageContent(self::IMAGE, SourceType::BASE64, 'image/png'));
        $second->addMetadata('original', 'queued attachment');
        $command = new class ($first, $second) implements CommandInterface {
            public function __construct(private UserMessage $first, private UserMessage $second) {}
            public function name(): string
            {
                return '/photos';
            }
            public function describe(): string
            {
                return 'Send two photos.';
            }
            public function run(CommandAdapterInterface $adapter, string $value): void
            {
                $adapter->promptAgent($this->first);
                $adapter->promptAgent($this->second);
            }
        };
        $provider = new FakeAIProvider(new AssistantMessage('One.'), new AssistantMessage('Two.'));
        $agent = (new Agent())->setThreadId('test-thread');
        $agent->setAiProvider($provider);
        $terminal = new VirtualTerminal(rows: 30);
        EventLoop::queue(static fn() => $terminal->simulateInput("/photos\r"));
        EventLoop::delay(0.3, static fn() => $terminal->simulateInput("\x03"));

        Tui::make($agent)
            ->setSessionStore(new SessionStore(new InMemoryStorage(), 'local'))
            ->setTerminal($terminal)
            ->setCommands((new Commands())->addCommand($command))
            ->run();

        self::assertCount(2, $provider->getRecorded());
        self::assertEquals($first, $provider->getRecorded()[0]->messages[0]);
        self::assertEquals($second, $provider->getRecorded()[1]->messages[2]);
        self::assertStringContainsString('[Image]', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testRecalledInputKeepsItsImageWhenItsTextIsPrepared(): void
    {
        $inputs = new InputHistory(new InMemoryStorage());
        $image = new ImageContent(self::IMAGE, SourceType::BASE64, 'image/png');
        $message = new UserMessage('Original');
        $message->addContent($image);
        $inputs->record($message);
        $provider = new FakeAIProvider(new AssistantMessage('Received.'));
        $agent = (new Agent())->setThreadId('test-thread');
        $agent->setAiProvider($provider);
        $terminal = new VirtualTerminal(rows: 30);
        EventLoop::queue(static fn() => $terminal->simulateInput("\x1b[A\r"));
        EventLoop::delay(0.15, static fn() => $terminal->simulateInput("\x03"));

        Tui::make($agent)
            ->setSessionStore(new SessionStore(new InMemoryStorage(), 'local'))
            ->setUserMessageProcessors((new UserMessageProcessors())->addProcessor(new EnvelopeProcessor('A')))
            ->setTerminal($terminal)
            ->setInputHistory($inputs)
            ->run();

        $sent = $provider->getRecorded()[0]->messages[0];
        self::assertSame('A[Original]', $sent->getContent());
        self::assertCount(2, $sent->getContentBlocks());
        self::assertInstanceOf(ImageContent::class, $sent->getContentBlocks()[1]);
        self::assertSame($image->content, $sent->getContentBlocks()[1]->content);
        self::assertStringContainsString('[Image]', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testLiveInputStaysOriginalAndReloadProjectsPreparedAttachments(): void
    {
        $processor = new class implements UserMessageProcessorInterface {
            public function forAgent(UserMessage $message): UserMessage
            {
                $result = clone $message;
                $result->addContent(new FileContent('ZmlsZQ==', SourceType::BASE64, 'application/pdf', 'report.pdf'));

                return $result;
            }
            public function forDisplay(UserMessage $message): UserMessage
            {
                if (count($message->getContentBlocks()) < 2) {
                    return clone $message;
                }
                TestCase::assertInstanceOf(FileContent::class, $message->getContentBlocks()[1]);
                $result = clone $message;
                $result->setContents('Message with attachment');

                return $result;
            }
        };
        $provider = new FakeAIProvider(new AssistantMessage('Received.'));
        $agent = (new Agent())->setThreadId('test-thread');
        $agent->setAiProvider($provider);
        $terminal = new VirtualTerminal(rows: 30);
        EventLoop::queue(static fn() => $terminal->simulateInput("Original request\r"));
        EventLoop::delay(0.15, static fn() => $terminal->simulateInput("\x03"));

        $store = new SessionStore(new InMemoryStorage(), 'local');
        $session = $store->create();
        $tui = Tui::make($agent)
            ->setSessionStore($store)
            ->setSession($session)
            ->setUserMessageProcessors($processor);
        $tui->setTerminal($terminal);
        $tui->run();

        $saved = $session->getMessages()[0];
        self::assertSame('Original request', $saved->getContent());
        self::assertCount(2, $saved->getContentBlocks());
        self::assertInstanceOf(FileContent::class, $saved->getContentBlocks()[1]);
        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString('❯ Original request', $display);
        self::assertStringNotContainsString('Message with attachment', $display);
        self::assertStringNotContainsString('[File: report.pdf]', $display);

        $reopened = new VirtualTerminal(rows: 30);
        EventLoop::delay(0.05, static fn() => $reopened->simulateInput("\x03"));
        Tui::make(new Agent())
            ->setSessionStore($store)
            ->setSession($session)
            ->setUserMessageProcessors($processor)
            ->setTerminal($reopened)
            ->run();

        self::assertStringContainsString('❯ Message with attachment', AnsiUtils::stripAnsiCodes($reopened->getOutput()));
        $restored = $store->get($session->getKey());
        self::assertNotNull($restored);
        self::assertCount(2, $restored->getMessages()[0]->getContentBlocks());
    }


}

final readonly class EnvelopeProcessor implements UserMessageProcessorInterface
{
    public function __construct(private string $label) {}

    public function forAgent(UserMessage $input): UserMessage
    {
        return $this->withText($input, $this->label . '[' . ($input->getContent() ?? '') . ']');
    }

    public function forDisplay(UserMessage $content): UserMessage
    {
        $text = $content->getContent() ?? '';
        if (str_starts_with($text, $this->label . '[') && str_ends_with($text, ']')) {
            $text = substr($text, strlen($this->label) + 1, -1);
        }

        return $this->withText($content, $text);
    }

    private function withText(UserMessage $original, string $text): UserMessage
    {
        $result = clone $original;
        $result->setContents($text);
        foreach ($original->getContentBlocks() as $block) {
            if (!$block instanceof TextContent) {
                $result->addContent(clone $block);
            }
        }

        return $result;
    }
}
