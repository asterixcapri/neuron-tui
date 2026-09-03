<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronAI\HttpClient\AmpHttpClient;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronAI\Tools\Toolkits\Calendar\CalendarToolkit;
use NeuronAI\Tools\Toolkits\FileSystem\FileSystemToolkit;
use NeuronAI\Tools\Toolkits\Jina\JinaToolkit;
use NeuronTui\Command\Clear;
use NeuronTui\Command\Help;
use NeuronTui\Command\Leave;
use NeuronTui\Command\Resume;
use NeuronTui\Tui;
use NeuronTui\Session\FileSessionProvider;
use Symfony\Component\Dotenv\Dotenv;

require_once __DIR__ . '/vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/.env');

$openAiKey = $_ENV['OPENAI_API_KEY'] ?? '';
$model = $_ENV['OPENAI_MODEL'] ?? '';
$jinaKey = $_ENV['JINA_API_KEY'] ?? '';

if ($openAiKey === '') {
    fwrite(STDERR, "Missing environment value: OPENAI_API_KEY\n");
    exit(1);
}

if ($model === '') {
    fwrite(STDERR, "Missing environment value: OPENAI_MODEL\n");
    exit(1);
}

$agent = Agent::make()
    ->toolMaxRuns(PHP_INT_MAX)
    ->setAiProvider(new OpenAIResponses(
        key: $openAiKey,
        model: $model,
        httpClient: new AmpHttpClient(),
    ))
    ->addTool([
        new FileSystemToolkit(),
        new CalendarToolkit(),
    ]);

if ($jinaKey !== '') {
    $agent->addTool(new JinaToolkit($jinaKey));
}

$sessionProvider = new FileSessionProvider(__DIR__ . '/sessions');

$agent->setChatHistory($sessionProvider->start());

$tui = Tui::make($agent)
    ->setTitle('Neuron TUI Demo')
    ->setSubtitle("OpenAI Responses · {$model}")
    ->addCommand([
        new Clear($sessionProvider),
        new Resume($sessionProvider),
        new Leave(),
        new Help(),
    ]);

$tui->run();
