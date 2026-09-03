<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Session;

use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\History\HistoryTrimmerInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\Tool;
use NeuronTui\Session\StorageChatHistory;
use NeuronTui\Storage\InMemoryStorage;
use PHPUnit\Framework\TestCase;

final class StorageChatHistoryTest extends TestCase
{
    public function testItExtendsNeuronAiHistory(): void
    {
        self::assertInstanceOf(
            AbstractChatHistory::class,
            new StorageChatHistory(new InMemoryStorage(), 'sessions', 'one'),
        );
    }

    public function testMessagesRoundTripWithTheirSupportedContent(): void
    {
        $storage = new InMemoryStorage();
        $history = new StorageChatHistory($storage, 'sessions', 'known.json');
        $question = new UserMessage([
            (new TextContent('What is shown?'))->setMetadata(['part' => 1]),
            new ImageContent(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwC'
                    . 'AAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
                SourceType::BASE64,
                'image/png',
            ),
        ]);
        $answer = (new AssistantMessage([
            new ReasoningContent('I inspected it.', 'reasoning-1'),
            new TextContent('A small diagram.'),
        ]))->setUsage(new Usage(18, 4));
        $tool = Tool::make('inspect', 'Inspect an image')
            ->setParameters(['type' => 'object'])
            ->setInputs(['detail' => 'high'])
            ->setCallId('call-1');
        $result = Tool::make('inspect', 'Inspect an image')
            ->setInputs(['detail' => 'high'])
            ->setCallId('call-1')
            ->setResult('diagram');

        $history->addMessage($question);
        $history->addMessage($answer);
        $history->addMessage(new ToolCallMessage(tools: [$tool]));
        $history->addMessage(new ToolResultMessage([$result]));

        $reopened = new StorageChatHistory(
            $storage,
            'sessions',
            'known.json',
        );

        $messages = $reopened->getMessages();

        self::assertCount(4, $messages);
        self::assertEquals(
            $question->getContentBlocks(),
            $messages[0]->getContentBlocks(),
        );
        self::assertEquals(
            $answer->getContentBlocks(),
            $messages[1]->getContentBlocks(),
        );
        self::assertEquals(new Usage(18, 4), $messages[1]->getUsage());
        self::assertInstanceOf(ToolCallMessage::class, $messages[2]);
        self::assertSame('inspect', $messages[2]->getTools()[0]->getName());
        self::assertSame('call-1', $messages[2]->getTools()[0]->getCallId());
        self::assertInstanceOf(ToolResultMessage::class, $messages[3]);
        self::assertSame('diagram', $messages[3]->getTools()[0]->getResult());
    }

    public function testSavingReplacesOnlyTheSelectedStorageValue(): void
    {
        $storage = new InMemoryStorage();
        $storage->write('sessions', 'other.json', 'untouched');
        $history = new StorageChatHistory(
            $storage,
            'sessions',
            'current.json',
        );

        $history->addMessage(new UserMessage('First'));
        $first = $storage->read('sessions', 'current.json');
        $history->addMessage(new AssistantMessage('Second'));

        self::assertNotNull($first);
        self::assertStringContainsString('First', $first);
        self::assertSame(
            json_encode($history->jsonSerialize(), JSON_THROW_ON_ERROR),
            $storage->read('sessions', 'current.json'),
        );
        self::assertSame(
            'untouched',
            $storage->read('sessions', 'other.json'),
        );
    }

    public function testTrimmingPersistsTheHistoryNeuronAiKeeps(): void
    {
        $storage = new InMemoryStorage();
        $trimmer = new class implements HistoryTrimmerInterface {
            public function getTotalTokens(): int
            {
                return 73;
            }

            public function trim(array $messages, int $contextWindow): array
            {
                return array_slice($messages, -2);
            }
        };
        $history = new StorageChatHistory(
            $storage,
            'sessions',
            'trimmed.json',
            trimmer: $trimmer,
        );

        $history->addMessage(new UserMessage('Discarded'));
        $history->addMessage(new AssistantMessage('Kept answer'));
        $history->addMessage(new UserMessage('Kept question'));

        self::assertSame(73, $history->calculateTotalUsage());
        self::assertSame(
            ['Kept answer', 'Kept question'],
            array_map(
                static fn (Message $message): ?string => $message->getContent(),
                (new StorageChatHistory(
                    $storage,
                    'sessions',
                    'trimmed.json',
                ))->getMessages(),
            ),
        );
    }

    public function testClearingPersistsAnEmptyHistory(): void
    {
        $storage = new InMemoryStorage();
        $history = new StorageChatHistory(
            $storage,
            'sessions',
            'cleared.json',
        );
        $history->addMessage(new UserMessage('Remove me'));

        $history->flushAll();

        self::assertSame('[]', $storage->read('sessions', 'cleared.json'));
        self::assertSame(
            [],
            (new StorageChatHistory(
                $storage,
                'sessions',
                'cleared.json',
            ))->getMessages(),
        );
    }
}
