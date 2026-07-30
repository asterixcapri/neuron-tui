<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronCli\NeuronCli;

require_once __DIR__ . '/../vendor/autoload.php';

$key = trim((string) getenv('OPENAI_API_KEY'));
$model = trim((string) getenv('OPENAI_MODEL'));

if ($key === '' || $model === '') {
    fwrite(
        STDERR,
        "Set OPENAI_API_KEY and OPENAI_MODEL before starting the demo.\n",
    );

    exit(1);
}

$agent = new Agent();
$agent->setAiProvider(new OpenAIResponses(
    key: $key,
    model: $model,
));

(new NeuronCli(
    agent: $agent,
    title: 'Neuron CLI Demo',
    subtitle: "OpenAI Responses · {$model}",
))->run();
