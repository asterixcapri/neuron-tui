<?php

declare(strict_types=1);

use NeuronTui\Command\ClearCommand;
use NeuronTui\Command\HelpCommand;
use NeuronTui\Command\LeaveCommand;
use NeuronTui\Command\ResumeCommand;
use NeuronTui\Storage\FileStorage;
use NeuronTui\Tui;
use NeuronTuiDemo\DemoAgent;
use NeuronTuiDemo\ModelCommand;
use Symfony\Component\Dotenv\Dotenv;

require_once __DIR__ . '/vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/.env');

$agent = DemoAgent::make();

$tui = Tui::make($agent)
    ->setStorage(new FileStorage(__DIR__ . '/.storage'))
    ->setFiglet('NeuronTUI')
    ->setTitle('Neuron TUI Demo')
    ->setSubtitle('Powered by Neuron AI')
    ->addCommand([
        new ClearCommand(),
        new ResumeCommand(),
        new ModelCommand(),
        new LeaveCommand(),
        new HelpCommand(),
    ]);

$tui->run();
