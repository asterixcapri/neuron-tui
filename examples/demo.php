<?php

declare(strict_types=1);

use NeuronInteraction\Command\ClearCommand;
use NeuronInteraction\Command\Commands;
use NeuronInteraction\Session\Sessions;
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

$agent = DemoAgent::make();

$storage = new FileStorage(__DIR__ . '/.storage');
$sessions = new Sessions($storage);
$agent->setChatHistory($sessions->start()); // Or resume an explicitly chosen key.
$commands = (new Commands())->addCommand([
    new ClearCommand(),
    new ResumeCommand(),
    new ModelCommand(),
    new LeaveCommand(),
    new HelpCommand(),
]);

// Startup keeps this explicitly selected History. These Sessions own its
// persistence, so /resume can recover it after /clear.
Tui::make(
    $agent,
    commands: $commands,
    sessions: $sessions,
    inputHistory: new InputHistory($storage),
)
    ->setFiglet('NeuronTUI')
    ->setTitle('Neuron TUI Demo')
    ->setSubtitle('Powered by Neuron AI')
    ->run();
