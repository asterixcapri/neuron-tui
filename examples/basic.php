<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronTui\Tui;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__ . '/vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/.env');

$key = $_ENV['OPENAI_API_KEY'] ?? null;
if (!\is_string($key) || \trim($key) === '') {
    \fwrite(\STDERR, "Set OPENAI_API_KEY in examples/.env before running this example.\n");
    exit(1);
}

$agent = Agent::make()
    ->setThreadId('demo');

$agent->setAiProvider(new OpenAIResponses(
    key: $key,
    model: 'gpt-5.4-nano'
));

Tui::make($agent)->run();
