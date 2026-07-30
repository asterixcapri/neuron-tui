<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronAI\HttpClient\AmpHttpClient;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronCli\NeuronCli;

require_once __DIR__ . '/../vendor/autoload.php';

function environmentValue(string $name): string
{
    static $values = null;
    $value = getenv($name);

    if (empty($value)) {
        $values ??= parse_ini_file(
            __DIR__ . '/.env',
            scanner_mode: INI_SCANNER_RAW,
        );
        $value = $values[$name] ?? null;
    }

    if (empty($value)) {
        throw new RuntimeException("Missing environment value: {$name}");
    }

    return $value;
}

try {
    $key = environmentValue('OPENAI_API_KEY');
    $model = environmentValue('OPENAI_MODEL');
} catch (RuntimeException $exception) {
    fwrite(
        STDERR,
        $exception->getMessage() . "\n",
    );

    exit(1);
}

$agent = new Agent();
$agent->setAiProvider(new OpenAIResponses(
    key: $key,
    model: $model,
    httpClient: new AmpHttpClient(),
));

(new NeuronCli(
    agent: $agent,
    title: 'Neuron CLI Demo',
    subtitle: "OpenAI Responses · {$model}",
))->run();
