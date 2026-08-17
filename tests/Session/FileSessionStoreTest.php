<?php

declare(strict_types=1);

namespace NeuronCli\Tests\Session;

use NeuronAI\Chat\History\FileChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronCli\Session\FileSessionStore;
use NeuronCli\Session\SessionSummary;
use PHPUnit\Framework\TestCase;

final class FileSessionStoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir()
            . '/neuron-cli-sessions-'
            . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    public function testANewSessionStartsEmpty(): void
    {
        $store = new FileSessionStore($this->directory);

        self::assertSame([], $store->open()->getMessages());
    }

    public function testStartingANewSessionLeavesThePreviousOneStored(): void
    {
        $store = new FileSessionStore($this->directory);

        $first = $store->open();
        $first->addMessage(new UserMessage('The first subject'));
        $first->addMessage(new AssistantMessage('An answer.'));

        $second = $store->open();
        $second->addMessage(new UserMessage('The second subject'));

        self::assertCount(2, $this->storedFiles());
        $stored = $this->storedContents();
        self::assertStringContainsString('The first subject', $stored);
        self::assertStringContainsString('An answer.', $stored);
        self::assertStringContainsString('The second subject', $stored);
        self::assertSame(
            ['The second subject'],
            $this->contentsOf($second->getMessages()),
        );
    }

    public function testAStoredSessionIsReopenedByItsKey(): void
    {
        $stored = new FileChatHistory($this->directory, 'known-key');
        $stored->addMessage(new UserMessage('Written earlier'));

        $reopened = (new FileSessionStore($this->directory))
            ->open('known-key');

        self::assertSame(
            ['Written earlier'],
            $this->contentsOf($reopened->getMessages()),
        );
    }

    public function testTheDirectoryIsCreatedWhenItDoesNotExist(): void
    {
        $store = new FileSessionStore($this->directory . '/deeper');

        $store->open()->addMessage(new UserMessage('Anything'));

        self::assertDirectoryExists($this->directory . '/deeper');
    }

    public function testWithoutADirectoryTheSessionsLiveUnderTheProject(): void
    {
        $project = $this->directory . '/project';
        mkdir($project, 0o755, true);
        $previous = getcwd();
        self::assertIsString($previous);
        chdir($project);

        try {
            (new FileSessionStore())
                ->open()
                ->addMessage(new UserMessage('Anything'));
        } finally {
            chdir($previous);
        }

        self::assertCount(
            1,
            glob($project . '/.neuron/sessions/*') ?: [],
        );
    }

    public function testNothingIsListedBeforeASessionIsStored(): void
    {
        self::assertSame([], (new FileSessionStore($this->directory))->list());
    }

    public function testStoredSessionsAreListedMostRecentlyUsedFirst(): void
    {
        $store = new FileSessionStore($this->directory);
        $this->storeSession('older', 'The older subject', 1_700_000_000);
        $this->storeSession('newer', 'The newer subject', 1_700_003_600);

        $listed = $store->list();

        self::assertSame(
            ['The newer subject', 'The older subject'],
            array_map(
                static fn (SessionSummary $summary): string => $summary->title,
                $listed,
            ),
        );
        self::assertSame('newer', $listed[0]->key);
        self::assertSame(
            1_700_003_600,
            $listed[0]->lastUsedAt->getTimestamp(),
        );
    }

    public function testASessionIsTitledByTheFirstThingThePersonWrote(): void
    {
        $stored = new FileChatHistory($this->directory, 'titled');
        $stored->addMessage(new UserMessage('What the person asked'));
        $stored->addMessage(new AssistantMessage('An answer.'));
        $stored->addMessage(new UserMessage('A later question'));

        $listed = (new FileSessionStore($this->directory))->list();

        self::assertCount(1, $listed);
        self::assertSame('What the person asked', $listed[0]->title);
    }

    public function testASessionThatReceivedNoMessageIsNotListed(): void
    {
        $store = new FileSessionStore($this->directory);
        $store->open();
        file_put_contents($this->directory . '/neuron_empty.chat', '[]');
        $this->storeSession('asked', 'A question', 1_700_000_000);

        $listed = $store->list();

        self::assertCount(1, $listed);
        self::assertSame('asked', $listed[0]->key);
    }

    private function storeSession(
        string $key,
        string $question,
        int $lastUsedAt,
    ): void {
        $session = new FileChatHistory($this->directory, $key);
        $session->addMessage(new UserMessage($question));
        $session->addMessage(new AssistantMessage('An answer.'));
        touch($this->directory . '/neuron_' . $key . '.chat', $lastUsedAt);
    }

    /**
     * @return list<string>
     */
    private function storedFiles(): array
    {
        return glob($this->directory . '/*') ?: [];
    }

    private function storedContents(): string
    {
        return implode("\n", array_map(
            static fn (string $path): string => (string) file_get_contents(
                $path,
            ),
            $this->storedFiles(),
        ));
    }

    /**
     * @param array<Message> $messages
     *
     * @return list<string>
     */
    private function contentsOf(array $messages): array
    {
        return array_values(array_map(
            static fn (Message $message): string => (string) $message
                ->getContent(),
            $messages,
        ));
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
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }
}
