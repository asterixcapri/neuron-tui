<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronAI\HttpClient\AmpHttpClient;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronCli\NeuronCli;

require_once __DIR__ . '/../vendor/autoload.php';

function environmentValue(string $name): string
{
    $processValue = getenv($name);

    if (is_string($processValue) && trim($processValue) !== '') {
        return trim($processValue);
    }

    /** @var array<string, string>|null $values */
    static $values = null;

    if ($values === null) {
        $path = __DIR__ . '/.env';

        if (!is_readable($path)) {
            throw new RuntimeException(
                'Missing readable examples/.env file. '
                . 'Copy examples/.env.example to examples/.env.',
            );
        }

        $values = parse_ini_file(
            $path,
            scanner_mode: INI_SCANNER_RAW,
        );

        if ($values === false) {
            throw new RuntimeException('Unable to parse the .env file.');
        }
    }

    $value = $values[$name] ?? null;

    if (!is_string($value) || trim($value) === '') {
        throw new RuntimeException("Missing environment value: {$name}");
    }

    return trim($value);
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
