<?php

declare(strict_types=1);

use NeuronAI\HttpClient\AmpHttpClient;
use NeuronInteraction\Command\ClearCommand;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\HelpCommand;
use NeuronInteraction\Command\LeaveCommand;
use NeuronInteraction\Command\ResumeCommand;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Http\StoppableHttpClient;
use NeuronInteraction\Http\StopSignal;
use NeuronInteraction\InputHistory\InputHistory;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\FileStorage;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronTui\Tui;
use NeuronTuiDemo\AIProviderFactory;
use NeuronTuiDemo\DemoAgent;
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

$agent = DemoAgent::make();
$agent->setAiProvider(AIProviderFactory::create($modelId, $httpClient));
$agent->setChatHistory($sessionStore->create());

$commands = (new Commands())->addCommand([
    new ClearCommand(),
    new ResumeCommand(),
    new ModelCommand($httpClient),
    new LeaveCommand(),
    new HelpCommand(),
]);

Tui::make(
    $agent,
    commands: $commands,
    sessionStore: $sessionStore,
    configurationStore: $configurationStore,
    inputHistory: $inputHistory,
)
    ->setStopSignal($stopSignal)
    ->setFiglet('NeuronTUI')
    ->setTitle('Neuron TUI Demo')
    ->setSubtitle('Powered by Neuron AI')
    ->run();
