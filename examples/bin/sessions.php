<?php

declare(strict_types=1);

use NeuronChatCore\Command\ClearCommand;
use NeuronChatCore\Command\Commands;
use NeuronChatCore\Command\ResumeCommand;
use NeuronChatCore\Conversation\ConversationRuntime as CoreRuntime;
use NeuronChatCore\Session\SessionStore;
use NeuronChatCore\Storage\FileStorage;
use NeuronTui\Tui;
use NeuronTuiDemo\AIProviderFactory;
use NeuronTuiDemo\DemoAgent;
use Symfony\Component\Dotenv\Dotenv;

require_once __DIR__ . '/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/../.env');

$sessionStore = new SessionStore(new FileStorage(__DIR__ . '/../.storage'), 'local');

$agent = DemoAgent::make();
$agent->setAiProvider(AIProviderFactory::create('openai:gpt-5.4-nano'));
// Titles are generated automatically after successful turns and appear in /resume.
$session = $sessionStore->create();

$commands = (new Commands())->addCommand([
    new ClearCommand(),
    new ResumeCommand(),
]);

Tui::make(
    new CoreRuntime($agent, $sessionStore, session: $session),
    commands: $commands,
)->run();
