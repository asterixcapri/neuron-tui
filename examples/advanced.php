<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronAI\HttpClient\Amp\AmpHttpClient;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronAI\Tools\Toolkits\FileSystem\FileSystemToolkit;
use NeuronInteraction\Command\ClearCommand;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\ExitCommand;
use NeuronInteraction\Command\HelpCommand;
use NeuronInteraction\Command\ResumeCommand;
use NeuronInteraction\Conversation;
use NeuronInteraction\InputHistory\InputHistory;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Session\SessionTitleGenerator;
use NeuronInteraction\Storage\FileStorage;
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

$aiProvider = new OpenAIResponses(
    key: $key,
    model: 'gpt-5.4-nano',
    httpClient: new AmpHttpClient(),
);

$agent->setAiProvider($aiProvider);

// FileStorage creates the directory and keeps sessions across application restarts.
$storage = new FileStorage(__DIR__ . '/.storage');
$sessionStore = new SessionStore($storage, 'local');
$session = $sessionStore->create();

$conversation = new Conversation($agent, $session);
$conversation->setInputHistory(new InputHistory($storage));
$conversation->setSessionTitleGenerator(new SessionTitleGenerator($aiProvider));
$conversation->setCommands(new Commands(
    new HelpCommand(),
    new ClearCommand($sessionStore),
    new ResumeCommand($sessionStore),
    new ExitCommand(),
));

Tui::make($conversation)
    ->setTitle('Persistent Filesystem Agent')
    ->setDescription('Sessions saved locally · /resume to reopen · /clear to start fresh')
    ->run();
