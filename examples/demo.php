<?php

declare(strict_types=1);

use NeuronInteraction\Agent\AgentFactoryRegistry;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Command\ClearCommand;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\InputHistory\InputHistory;
use NeuronInteraction\Command\HelpCommand;
use NeuronInteraction\Command\LeaveCommand;
use NeuronInteraction\Command\ResumeCommand;
use NeuronInteraction\Storage\FileStorage;
use NeuronTui\Tui;
use NeuronTuiDemo\DemoAgent;
use NeuronTuiDemo\ModelCommand;
use Symfony\Component\Dotenv\Dotenv;

require_once __DIR__ . '/vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/.env');

$storage = new FileStorage(__DIR__ . '/.storage');
$sessionStore = new SessionStore($storage, 'local');
$inputHistory = new InputHistory($storage);
$configurationStore = new ConfigurationStore($storage, 'local');
$configurationStore->read('agent') ?? $configurationStore->create('agent', [
    'model' => 'openai:gpt-5.4-nano',
]);
$agentFactoryRegistry = new AgentFactoryRegistry();
$agentFactoryRegistry->register('demo', DemoAgent::class);

$commands = (new Commands())->addCommand([
    new ClearCommand(),
    new ResumeCommand(),
    new ModelCommand(),
    new LeaveCommand(),
    new HelpCommand(),
]);

Tui::make(
    agentFactoryRegistry: $agentFactoryRegistry,
    initialAgentIdentifier: 'demo',
    commands: $commands,
    sessionStore: $sessionStore,
    inputHistory: $inputHistory,
    configurationStore: $configurationStore,
)
    ->setFiglet('NeuronTUI')
    ->setTitle('Neuron TUI Demo')
    ->setSubtitle('Powered by Neuron AI')
    ->run();
