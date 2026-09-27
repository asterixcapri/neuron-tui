<?php

declare(strict_types=1);

namespace NeuronTuiDemo\Tests;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronInteraction\InputHistory\InputHistory;
use NeuronInteraction\Message\UserMessageFactory;
use NeuronInteraction\Message\UserMessageProcessors;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronTui\Tui;
use NeuronTuiDemo\FileReferenceProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use RuntimeException;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class FileReferenceProcessorTest extends TestCase
{
    public function testFileContentsAreHiddenAfterPersistenceWithoutLosingAttachmentsOrMetadata(): void
    {
        $text = new TextContent('Explain @report.txt');
        $text->addMetadata('source', 'typed');
        $message = new UserMessage([
            $text,
            new FileContent('ZmlsZQ==', SourceType::BASE64, 'application/pdf', 'report.pdf'),
        ]);
        $message->addMetadata('project', 'demo');
        $processor = new FileReferenceProcessor(__DIR__ . '/fixtures');

        $prepared = $processor->forAgent($message);
        $decoded = json_decode(json_encode($prepared, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $data = [];
        foreach ($decoded as $key => $value) {
            self::assertIsString($key);
            $data[$key] = $value;
        }
        $restored = UserMessageFactory::fromArray($data);
        $display = $processor->forDisplay($restored);

        self::assertCount(2, $message->getContentBlocks());
        self::assertCount(3, $prepared->getContentBlocks());
        self::assertStringContainsString('Demo file content.', $prepared->getContent() ?? '');
        self::assertEquals($message, $display);
        self::assertEquals($display, $processor->forDisplay($restored));
        self::assertCount(3, $restored->getContentBlocks());
    }
    public function testTuiSendsFileContentsButDisplaysAndRecallsTheOriginalInput(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('The file contains demo text.'));
        $agent = new Agent();
        $agent->setAiProvider($provider);
        $terminal = new VirtualTerminal(rows: 30);
        $inputHistory = new InputHistory(new InMemoryStorage());
        $processors = (new UserMessageProcessors())->addProcessor([
            new FileReferenceProcessor(__DIR__ . '/fixtures'),
        ]);

        EventLoop::queue(static fn () => $terminal->simulateInput("Explain @report.txt\r"));
        EventLoop::delay(0.15, static fn () => $terminal->simulateInput("\x03"));

        Tui::make(
            $agent,
            $terminal,
            inputHistory: $inputHistory,
            userMessageProcessors: $processors,
        )->run();

        self::assertStringContainsString('Demo file content.', $provider->getRecorded()[0]->messages[0]->getContent() ?? '');
        self::assertSame('Explain @report.txt', $inputHistory->older()?->getContent());
        $display = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        self::assertStringContainsString('Explain @report.txt', $display);
        self::assertStringNotContainsString('Demo file content.', $display);
    }

    public function testRepeatedReferencesAreExpandedOnlyOnce(): void
    {
        $processor = new FileReferenceProcessor(__DIR__ . '/fixtures');
        $original = new UserMessage('Compare @report.txt with @report.txt');

        $prepared = $processor->forAgent($original);

        self::assertCount(2, $prepared->getContentBlocks());
        self::assertSame('Compare @report.txt with @report.txt', $processor->forDisplay($prepared)->getContent());
    }

    public function testMessagesWithoutReferencesAreUnchangedCopies(): void
    {
        $processor = new FileReferenceProcessor(__DIR__ . '/fixtures');
        $message = new UserMessage('Hello');

        $prepared = $processor->forAgent($message);

        self::assertEquals($message, $prepared);
        self::assertNotSame($message, $prepared);
        self::assertEquals($message, $processor->forDisplay($prepared));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidReferences(): iterable
    {
        yield 'missing file' => ['@missing.txt'];
        yield 'directory' => ['@.'];
        yield 'outside directory' => ['@../FileReferenceProcessorTest.php'];
    }

    #[DataProvider('invalidReferences')]
    public function testInvalidReferencesRejectSubmissionWithoutChangingTheOriginal(string $reference): void
    {
        $processor = new FileReferenceProcessor(__DIR__ . '/fixtures');
        $message = new UserMessage('Explain @report.txt and ' . $reference);

        try {
            $processor->forAgent($message);
            self::fail('Invalid file references must reject submission.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString($reference, $exception->getMessage());
            self::assertCount(1, $message->getContentBlocks());
            self::assertSame('Explain @report.txt and ' . $reference, $message->getContent());
        }
    }
}
