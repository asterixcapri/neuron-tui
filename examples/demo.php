<?php

declare(strict_types=1);

use NeuronInteraction\Agent\AgentFactoryRegistry;
use NeuronInteraction\Configuration\Configuration;
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
$configuration = $configurationStore->read('global') ?? $configurationStore->create('global', [
    'agent' => 'demo',
    'model' => 'openai:gpt-5.4-nano',
]);
$agentFactoryRegistry = new AgentFactoryRegistry();
$agentFactoryRegistry->register('demo', static function (Configuration $configuration): DemoAgent {
    $model = $configuration->get('model');
    if (!is_string($model) || !preg_match('/^(openai|anthropic):[^:]+$/D', $model)) {
        throw new InvalidArgumentException('The demo configuration requires a provider:model identifier.');
    }

    return (new DemoAgent())->setModelId($model);
});
$agent = $agentFactoryRegistry->create($configuration);

$agent->setChatHistory($sessionStore->create()); // Or resume an explicitly chosen key.

$commands = (new Commands())->addCommand([
    new ClearCommand(),
    new ResumeCommand(),
    new ModelCommand(),
    new LeaveCommand(),
    new HelpCommand(),
]);

// Startup keeps this explicitly selected History. This SessionStore owns its
// persistence, so /resume can recover it after /clear.
Tui::make(
    $agent,
    commands: $commands,
    sessionStore: $sessionStore,
    inputHistory: $inputHistory,
    agentFactoryRegistry: $agentFactoryRegistry,
    configurationStore: $configurationStore,
)
    ->setFiglet('NeuronTUI')
    ->setTitle('Neuron TUI Demo')
    ->setSubtitle('Powered by Neuron AI')
    ->run();
