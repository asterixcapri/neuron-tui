<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronAI\HttpClient\Amp\AmpHttpClient;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronAI\Tools\Toolkits\FileSystem\FileSystemToolkit;
use NeuronInteraction\Conversation;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\InMemoryStorage;
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
    ->setThreadId('demo')
    ->addTool(FileSystemToolkit::make(__DIR__));

$agent->setAiProvider(new OpenAIResponses(
    key: $key,
    model: 'gpt-5.4-nano',
    httpClient: new AmpHttpClient(),
));

$session = (new SessionStore(new InMemoryStorage(), 'local'))->create();
$conversation = new Conversation($agent, $session);

Tui::make($conversation)
    ->setFiglet("Neuron TUI")
    ->setTitle('Basic filesystem agent')
    ->setDescription('Explore, read and edit files in the examples directory')
    ->run();
