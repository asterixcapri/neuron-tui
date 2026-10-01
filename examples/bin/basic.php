<?php

declare(strict_types=1);

use NeuronChatCore\Conversation\ConversationRuntime as CoreRuntime;
use NeuronChatCore\Session\SessionStore;
use NeuronChatCore\Storage\InMemoryStorage;
use NeuronTui\Tui;
use NeuronTuiDemo\AIProviderFactory;
use NeuronTuiDemo\DemoAgent;
use Symfony\Component\Dotenv\Dotenv;

require_once __DIR__ . '/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/../.env');

$agent = DemoAgent::make();
$agent->setAiProvider(AIProviderFactory::create('openai:gpt-5.4-nano'));

Tui::make(new CoreRuntime($agent, new SessionStore(new InMemoryStorage(), 'local')))->run();
