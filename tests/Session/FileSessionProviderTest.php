<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Session;

use InvalidArgumentException;
use NeuronAI\Chat\History\FileChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronTui\Session\FileSessionProvider;
use NeuronTui\Session\Session;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class FileSessionProviderTest extends TestCase
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

    public function testANewSessionStartsEmpty(): void
    {
        $provider = new FileSessionProvider($this->directory);

        self::assertSame([], $provider->start()->getMessages());
    }

    public function testStartingANewSessionLeavesThePreviousOneStored(): void
    {
        $provider = new FileSessionProvider($this->directory);

        $first = $provider->start();
        $first->addMessage(new UserMessage('The first subject'));
        $first->addMessage(new AssistantMessage('An answer.'));

        $second = $provider->start();
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

        $reopened = (new FileSessionProvider($this->directory))
            ->resume('known-key');

        self::assertSame(
            ['Written earlier'],
            $this->contentsOf($reopened->getMessages()),
        );
    }

    public function testTheDirectoryIsCreatedWhenItDoesNotExist(): void
    {
        $provider = new FileSessionProvider($this->directory . '/deeper');

        $provider->start()->addMessage(new UserMessage('Anything'));

        self::assertDirectoryExists($this->directory . '/deeper');
    }

    /**
     * A Session can only land where the Host Application named, so there is
     * no provider without a directory to construct in the first place.
     */
    public function testTheDirectoryIsRequired(): void
    {
        $constructor = (new ReflectionClass(FileSessionProvider::class))
            ->getConstructor();

        self::assertNotNull($constructor);
        self::assertSame(1, $constructor->getNumberOfRequiredParameters());
    }

    public function testNothingIsListedBeforeASessionIsStored(): void
    {
        self::assertSame(
            [],
            (new FileSessionProvider($this->directory))->list(),
        );
    }

    public function testStoredSessionsAreListedMostRecentlyUsedFirst(): void
    {
        $provider = new FileSessionProvider($this->directory);
        $this->storeSession('older', 'The older subject', 1_700_000_000);
        $this->storeSession('newer', 'The newer subject', 1_700_003_600);

        $listed = $provider->list();

        self::assertSame(
            ['The newer subject', 'The older subject'],
            array_map(
                static fn (Session $session): string => $session->title,
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

        $listed = (new FileSessionProvider($this->directory))->list();

        self::assertCount(1, $listed);
        self::assertSame('What the person asked', $listed[0]->title);
    }

    public function testASessionThatReceivedNoMessageIsNotListed(): void
    {
        $provider = new FileSessionProvider($this->directory);
        $provider->start();
        file_put_contents($this->directory . '/neuron_empty.chat', '[]');
        $this->storeSession('asked', 'A question', 1_700_000_000);

        $listed = $provider->list();

        self::assertCount(1, $listed);
        self::assertSame('asked', $listed[0]->key);
    }

    public function testAnUnknownSessionCannotBeResumed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'No Session is named by that key.',
        );

        (new FileSessionProvider($this->directory))->resume('unknown');
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
