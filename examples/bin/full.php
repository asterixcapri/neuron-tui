<?php

declare(strict_types=1);

use NeuronAI\HttpClient\Amp\AmpHttpClient;
use NeuronInteraction\Command\ClearCommand;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\HelpCommand;
use NeuronInteraction\Command\LeaveCommand;
use NeuronInteraction\Command\ResumeCommand;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Http\StoppableHttpClient;
use NeuronInteraction\Http\StopSignal;
use NeuronInteraction\InputHistory\InputHistory;
use NeuronInteraction\Message\UserMessageProcessors;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\FileStorage;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronTui\Tui;
use NeuronTuiDemo\AIProviderFactory;
use NeuronTuiDemo\DemoAgent;
use NeuronTuiDemo\FileReferenceProcessor;
use NeuronTuiDemo\ModelCommand;
use Symfony\Component\Dotenv\Dotenv;

use function Amp\delay;

require_once __DIR__ . '/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/../.env');

$storage = new FileStorage(__DIR__ . '/../.storage');
$sessionStore = new SessionStore($storage, 'local');
$inputHistory = new InputHistory($storage);

$configurationStore = new ConfigurationStore($storage, 'local');
$modelId = $configurationStore->read('model', 'openai:gpt-5.4-nano');

$stopSignal = new StopSignal(new InMemoryStorage(), 'full');

$httpClient = new StoppableHttpClient(
    inner: new AmpHttpClient(),
    stopSignal: $stopSignal,
    onPoll: function (): void {
        delay(0);
    },
);

$agent = DemoAgent::make()->setThreadId(bin2hex(random_bytes(16)));
$agent->setAiProvider(AIProviderFactory::create($modelId, $httpClient));
$agent = ($sessionStore->create())->bindTo($agent);

$commands = (new Commands())->addCommand([
    new ClearCommand(),
    new ResumeCommand(),
    new ModelCommand($httpClient),
    new LeaveCommand(),
    new HelpCommand(),
]);

$userMessageProcessors = (new UserMessageProcessors())->addProcessor([
    new FileReferenceProcessor(__DIR__ . '/..'),
]);

Tui::make(
    $agent,
    commands: $commands,
    sessionStore: $sessionStore,
    configurationStore: $configurationStore,
    inputHistory: $inputHistory,
    userMessageProcessors: $userMessageProcessors,
    stopSignal: $stopSignal,
)
    ->setFiglet('NeuronTUI')
    ->setTitle('Neuron TUI Demo')
    ->setSubtitle('Powered by Neuron AI')
    ->run();
