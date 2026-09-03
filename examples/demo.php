<?php

declare(strict_types=1);

use NeuronTui\Command\Clear;
use NeuronTui\Command\Help;
use NeuronTui\Command\Leave;
use NeuronTui\Command\Resume;
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
    ->setTitle('Neuron TUI Demo')
    ->setSubtitle('Powered by Neuron AI')
    ->addCommand([
        new Clear($sessionProvider),
        new Resume($sessionProvider),
        new ModelCommand(),
        new Leave(),
        new Help(),
    ]);

$tui->run();
