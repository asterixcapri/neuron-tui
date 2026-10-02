<?php

declare(strict_types=1);

use NeuronInteraction\Command\Commands;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Conversation\ConversationRuntime;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\FileStorage;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronTui\Tui;
use NeuronTuiDemo\AIProviderFactory;
use NeuronTuiDemo\DemoAgent;
use NeuronTuiDemo\ModelCommand;
use Symfony\Component\Dotenv\Dotenv;

require_once __DIR__ . '/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/../.env');

$storage = new FileStorage(__DIR__ . '/../.storage');

$configurationStore = new ConfigurationStore($storage, 'local');
$modelId = $configurationStore->read('model', 'openai:gpt-5.4-nano');

$agent = DemoAgent::make();
$agent->setAiProvider(AIProviderFactory::create($modelId));

$commands = (new Commands())->addCommand(new ModelCommand());

Tui::make(
    new ConversationRuntime($agent, new SessionStore(new InMemoryStorage(), 'local')),
    commands: $commands,
    configurationStore: $configurationStore,
)->run();
