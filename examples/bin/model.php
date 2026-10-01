<?php

declare(strict_types=1);

use NeuronChatCore\Command\Commands;
use NeuronChatCore\Configuration\ConfigurationStore;
use NeuronChatCore\Conversation\ConversationRuntime as CoreRuntime;
use NeuronChatCore\Session\SessionStore;
use NeuronChatCore\Storage\FileStorage;
use NeuronChatCore\Storage\InMemoryStorage;
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
    new CoreRuntime($agent, new SessionStore(new InMemoryStorage(), 'local')),
    commands: $commands,
    configurationStore: $configurationStore,
)->run();
