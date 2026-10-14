<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\FileMessageStore;
use NeuronAI\HttpClient\Amp\AmpHttpClient;
use NeuronAI\HttpClient\StoppableHttpClient;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronAI\Tools\Toolkits\FileSystem\FileSystemToolkit;
use NeuronInteraction\Command\ClearCommand;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\ExitCommand;
use NeuronInteraction\Command\HelpCommand;
use NeuronInteraction\Command\ResumeCommand;
use NeuronInteraction\Conversation;
use NeuronInteraction\InputHistory\InputHistory;
use NeuronInteraction\Interruption\StopSignal;
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

$storage = new FileStorage(__DIR__ . '/.storage');
$sessionStore = new SessionStore($storage, new FileMessageStore(__DIR__ . '/.storage/messages'), 'local');
$session = $sessionStore->create();
$stopSignal = new StopSignal($storage, $session->getKey());

$agent = Agent::make()
    ->setThreadId('demo')
    ->addTool(FileSystemToolkit::make(__DIR__));

$aiProvider = new OpenAIResponses(
    key: $key,
    model: 'gpt-5.4-nano',
    httpClient: new StoppableHttpClient(new AmpHttpClient(), $stopSignal->stopCallback()),
);

$agent->setAiProvider($aiProvider);

$conversation = new Conversation($agent, $session);
$conversation->setStopSignal($stopSignal);
$conversation->setInputHistory(new InputHistory($storage, 'local'));
$conversation->setSessionTitleGenerator(new SessionTitleGenerator($aiProvider));
$conversation->setCommands(new Commands(
    new HelpCommand(),
    new ClearCommand($sessionStore),
    new ResumeCommand($sessionStore),
    new ExitCommand(),
));

(new Tui($conversation))
    ->setFiglet("Neuron TUI")
    ->setTitle('Persistent filesystem agent')
    ->setDescription('Explore files with locally saved sessions, input history and interruptible responses')
    ->run();
