<?php

declare(strict_types=1);

use NeuronInteraction\Command\ClearCommand;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Command\ResumeCommand;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\FileStorage;
use NeuronTui\Tui;
use NeuronTuiDemo\AIProviderFactory;
use NeuronTuiDemo\DemoAgent;
use Symfony\Component\Dotenv\Dotenv;

require_once __DIR__ . '/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/../.env');

$sessionStore = new SessionStore(new FileStorage(__DIR__ . '/../.storage'), 'local');

$agent = DemoAgent::make();
$agent->setAiProvider(AIProviderFactory::create('openai:gpt-5.4-nano'));
$agent->setChatHistory($sessionStore->create());

Tui::make(
    $agent,
    commands: new Commands([
        new ClearCommand(),
        new ResumeCommand()
    ]),
    sessionStore: $sessionStore,
)->run();
