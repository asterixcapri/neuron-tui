<?php

declare(strict_types=1);

use NeuronTui\Command\ClearCommand;
use NeuronTui\Command\HelpCommand;
use NeuronTui\Command\LeaveCommand;
use NeuronTui\Command\ResumeCommand;
use NeuronTui\Tui;
use NeuronTui\Session\FileSessionProvider;
use NeuronTuiDemo\DemoAgent;
use NeuronTuiDemo\ModelCommand;
use Symfony\Component\Dotenv\Dotenv;

require_once __DIR__ . '/vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/.env');

$sessionProvider = new FileSessionProvider(__DIR__ . '/.sessions');

$agent = DemoAgent::make();
$agent->setChatHistory($sessionProvider->start());

$tui = Tui::make($agent)
    ->setFiglet('NeuronTUI')
    ->setTitle('Neuron TUI Demo')
    ->setSubtitle('Powered by Neuron AI')
    ->addCommand([
        new ClearCommand($sessionProvider),
        new ResumeCommand($sessionProvider),
        new ModelCommand(),
        new LeaveCommand(),
        new HelpCommand(),
    ]);

$tui->run();
