<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronAI\HttpClient\AmpHttpClient;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronTui\Http\ResponseStop;
use NeuronTui\Tui;
use Symfony\Component\Dotenv\Dotenv;

require_once __DIR__ . '/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/../.env');

$apiKey = $_ENV['OPENAI_API_KEY'] ?? null;
if (!is_string($apiKey) || $apiKey === '') {
    throw new RuntimeException('OPENAI_API_KEY not configured');
}

$stop = new ResponseStop();
$agent = new Agent();
$agent->setAiProvider(new OpenAIResponses(
    key: $apiKey,
    model: 'gpt-5.4-nano',
    httpClient: $stop->httpClient(new AmpHttpClient()),
));

Tui::make($agent)
    ->setResponseStop($stop)
    ->setSubtitle('HTTP stop experiment · Escape requests EOF')
    ->run();
