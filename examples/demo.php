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

    if (!is_string($value) || trim($value) === '') {
        $path = __DIR__ . '/.env';
        $values ??= is_readable($path)
            ? parse_ini_file($path, scanner_mode: INI_SCANNER_RAW)
            : false;
        $value = is_array($values) ? ($values[$name] ?? null) : null;
    }

    if (!is_string($value) || ($value = trim($value)) === '') {
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
