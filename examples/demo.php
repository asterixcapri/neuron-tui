<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Middleware\TodoPlanning;
use NeuronAI\Agent\Nodes\InferenceNode;
use NeuronAI\HttpClient\AmpHttpClient;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronAI\Tools\Toolkits\Calculator\CalculatorToolkit;
use NeuronAI\Tools\Toolkits\Calendar\CalendarToolkit;
use NeuronAI\Tools\Toolkits\FileSystem\FileSystemToolkit;
use NeuronAI\Tools\Toolkits\Jina\JinaToolkit;
use NeuronCli\NeuronCli;
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

$agent = new Agent();
$agent->toolMaxRuns(PHP_INT_MAX);
$agent->addMiddleware(InferenceNode::class, new TodoPlanning());
$agent->setAiProvider(new OpenAIResponses(
    key: $openAiKey,
    model: $model,
    httpClient: new AmpHttpClient(),
));
$agent->addTool(new FileSystemToolkit());
$agent->addTool(new CalendarToolkit());
$agent->addTool(new CalculatorToolkit());

if ($jinaKey !== '') {
    $agent->addTool(new JinaToolkit($jinaKey));
}

(new NeuronCli(
    agent: $agent,
    title: 'Neuron CLI Demo',
    subtitle: "OpenAI Responses · {$model}",
))->run();
