<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Session;

use InvalidArgumentException;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronTui\Session\Session;
use NeuronTui\Session\Sessions;
use NeuronTui\Storage\FileStorage;
use NeuronTui\Storage\InMemoryStorage;
use PHPUnit\Framework\TestCase;

final class SessionsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir()
            . '/neuron-tui-sessions-'
            . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    public function testStartMintsDistinctStorageSafeKeysAndEmptyHistories(): void
    {
        $sessions = new Sessions(new InMemoryStorage());
        $first = $sessions->start();
        $second = $sessions->start();

        self::assertSame([], $first->getMessages());
        self::assertSame([], $second->getMessages());

        $first->addMessage(new UserMessage('First'));
        $second->addMessage(new UserMessage('Second'));
        $keys = array_map(
            static fn (Session $session): string => $session->key,
            $sessions->list(),
        );

        self::assertCount(2, array_unique($keys));

        foreach ($keys as $key) {
            self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $key);
        }
    }

    public function testAnEmptySessionIsKnownButNotListed(): void
    {
        $storage = new InMemoryStorage();
        $sessions = new Sessions($storage);
        $sessions->start();
        $key = iterator_to_array($storage->entries('sessions'))[0]->key;

        self::assertSame([], $sessions->list());
        self::assertSame([], $sessions->resume($key)->getMessages());
    }

    public function testSessionsCanBeListedAndResumedByANewInstance(): void
    {
        $storage = new InMemoryStorage();
        $history = (new Sessions($storage))->start();
        $history->addMessage(new UserMessage('Written earlier'));
        $reopened = new Sessions($storage);
        $listed = $reopened->list();

        self::assertCount(1, $listed);
        self::assertSame('Written earlier', $listed[0]->title);
        self::assertSame(
            'Written earlier',
            $reopened->resume($listed[0]->key)->getMessages()[0]->getContent(),
        );
    }

    public function testListUsesTheOpeningWordsAndMostRecentUseOrder(): void
    {
        $sessions = new Sessions(new InMemoryStorage());
        $first = $sessions->start();
        $first->addMessage(new UserMessage('The older subject'));
        $first->addMessage(new AssistantMessage('An answer'));
        $second = $sessions->start();
        $second->addMessage(new UserMessage('The newer subject'));

        self::assertSame(
            ['The newer subject', 'The older subject'],
            array_map(
                static fn (Session $session): string => $session->title,
                $sessions->list(),
            ),
        );

        $first->addMessage(new UserMessage('A later question'));
        $listed = $sessions->list();

        self::assertSame('The older subject', $listed[0]->title);
        self::assertGreaterThan($listed[1]->lastUsedAt, $listed[0]->lastUsedAt);
        self::assertGreaterThan(0, $listed[0]->storageSize);
    }

    public function testUnknownKeysAreRejectedWithoutCreatingAHistory(): void
    {
        $storage = new InMemoryStorage();
        $sessions = new Sessions($storage);

        try {
            $sessions->resume('unknown');
            self::fail('An unknown Session was resumed.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                'No Session is named by that key.',
                $exception->getMessage(),
            );
        }

        self::assertNull($storage->read('sessions', 'unknown'));
        self::assertSame([], iterator_to_array($storage->entries('sessions')));
    }

    public function testFilePayloadIsKeyNamedJsonAndReportsItsDataSize(): void
    {
        $sessions = new Sessions(new FileStorage($this->directory));
        $history = $sessions->start();
        $history->addMessage(new UserMessage('Stored in a file'));
        $listed = $sessions->list();

        self::assertCount(1, $listed);
        $path = $this->directory . '/sessions/' . $listed[0]->key . '.json';
        self::assertFileExists($path);
        $contents = file_get_contents($path);
        self::assertIsString($contents);
        self::assertJson($contents);
        $document = (new FileStorage($this->directory))->read(
            'sessions',
            $listed[0]->key,
        );
        self::assertNotNull($document);
        self::assertSame($document->size(), $listed[0]->storageSize);
    }

    public function testLegacyFilesAreNeitherDiscoveredNorMigrated(): void
    {
        mkdir($this->directory, recursive: true);
        $legacy = $this->directory . '/neuron_legacy-key.chat';
        file_put_contents($legacy, '[{"content":"Old"}]');
        $contents = file_get_contents($legacy);
        $sessions = new Sessions(new FileStorage($this->directory));

        self::assertSame([], $sessions->list());

        try {
            $sessions->resume('legacy-key');
            self::fail('A legacy Session was resumed.');
        } catch (InvalidArgumentException) {
        }

        self::assertFileExists($legacy);
        self::assertSame($contents, file_get_contents($legacy));
        self::assertFileDoesNotExist(
            $this->directory . '/sessions/legacy-key.json',
        );
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
