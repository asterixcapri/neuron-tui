<?php

declare(strict_types=1);

use NeuronInteraction\Command\ClearCommand;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\InputHistory\InputHistory;
use NeuronInteraction\Command\HelpCommand;
use NeuronInteraction\Command\LeaveCommand;
use NeuronInteraction\Command\ResumeCommand;
use NeuronInteraction\Storage\FileStorage;
use NeuronTui\Tui;
use NeuronTuiDemo\AIProviderFactory;
use NeuronTuiDemo\DemoAgent;
use NeuronTuiDemo\ModelCommand;
use Symfony\Component\Dotenv\Dotenv;

require_once __DIR__ . '/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/../.env');

$storage = new FileStorage(__DIR__ . '/../.storage');
$sessionStore = new SessionStore($storage, 'local');
$inputHistory = new InputHistory($storage);

$configurationStore = new ConfigurationStore($storage, 'local');
$modelId = $configurationStore->read('model', 'openai:gpt-5.4-nano');

$agent = DemoAgent::make();
$agent->setAiProvider(AIProviderFactory::create($modelId));
$agent->setChatHistory($sessionStore->create());

$commands = (new Commands())->addCommand([
    new ClearCommand(),
    new ResumeCommand(),
    new ModelCommand(),
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
    ->setFiglet('NeuronTUI')
    ->setTitle('Neuron TUI Demo')
    ->setSubtitle('Powered by Neuron AI')
    ->run();
