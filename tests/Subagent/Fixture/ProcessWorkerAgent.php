<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Subagent\Fixture;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Testing\FakeAIProvider;

use function Amp\delay;

final class ProcessWorkerAgent extends Agent
{
    protected function provider(): AIProviderInterface
    {
        return new ProcessWorkerProvider();
    }
}

/** A deterministic provider that runs only inside process-worker tests. */
final class ProcessWorkerProvider extends FakeAIProvider
{
    public function chat(Message ...$messages): Message
    {
        $last = end($messages);

        if (!$last instanceof Message) {
            throw new ProviderException('A child Turn requires a message.');
        }

        $prompt = $last->getContent() ?? '';

        if ($prompt === 'provider-failure') {
            throw new ProviderException('provider implementation detail');
        }

        if ($prompt === 'worker-crash') {
            exit(70);
        }

        if (str_starts_with($prompt, 'wait:')) {
            self::record('started:'.substr($prompt, 5));
            $release = getenv('NEURON_TUI_PROCESS_TEST_RELEASE');

            while (is_string($release) && !is_file($release)) {
                delay(0.01);
            }

            self::record('finished:'.substr($prompt, 5));
        }

        $users = array_values(array_filter(array_map(
            static fn (Message $message): ?string => $message->getRole() === 'user'
                ? $message->getContent()
                : null,
            $messages,
        ), is_string(...)));

        return new AssistantMessage(json_encode([
            'pid' => getmypid(),
            'users' => $users,
        ], JSON_THROW_ON_ERROR));
    }

    private static function record(string $event): void
    {
        $path = getenv('NEURON_TUI_PROCESS_TEST_EVENTS');

        if (!is_string($path) || $path === '') {
            return;
        }

        file_put_contents($path, $event."\n", FILE_APPEND | LOCK_EX);
    }
}
