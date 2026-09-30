<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronAI\HttpClient\Amp\AmpHttpClient;
use NeuronInteraction\Http\StoppableHttpClient;
use NeuronInteraction\Http\StopSignal;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronTui\Tui;
use NeuronTuiDemo\AIProviderFactory;
use Symfony\Component\Dotenv\Dotenv;

use function Amp\delay;

require_once __DIR__ . '/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/../.env');

$storage = new InMemoryStorage();
$stopSignal = new StopSignal($storage, 'demo');

$agent = (new Agent())->setThreadId(bin2hex(random_bytes(16)));
$agent->setAiProvider(AIProviderFactory::create(
    modelId: 'openai:gpt-5.4-nano',
    httpClient: new StoppableHttpClient(
        inner: new AmpHttpClient(),
        stopSignal: $stopSignal,
        // Yield to the event loop so the TUI can read Escape while streaming.
        onPoll: function (): void {
            delay(0);
        },
    ),
));

Tui::make($agent, stopSignal: $stopSignal)
    ->setSubtitle('HTTP stop experiment · Escape requests EOF')
    ->run();
