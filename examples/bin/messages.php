<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronInteraction\InputHistory\InputHistory;
use NeuronInteraction\Message\UserMessageProcessors;
use NeuronInteraction\Storage\InMemoryStorage;
use NeuronTui\Tui;
use NeuronTuiDemo\AIProviderFactory;
use NeuronTuiDemo\FileReferenceProcessor;
use Symfony\Component\Dotenv\Dotenv;

require_once __DIR__ . '/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/../.env');

$agent = (new Agent())->setThreadId(\bin2hex(\random_bytes(16)));
$agent->setAiProvider(AIProviderFactory::create('openai:gpt-5.4-nano'));

$inputHistory = new InputHistory(new InMemoryStorage());
// Expand file references for the Agent. Try `Explain @composer.json` or
// `Compare @bin/basic.php @bin/sessions.php`; paths are relative to examples/.
$userMessageProcessors = (new UserMessageProcessors())->addProcessor([
    new FileReferenceProcessor(__DIR__ . '/..'),
]);

Tui::make(
    $agent,
    inputHistory: $inputHistory,
    userMessageProcessors: $userMessageProcessors,
)
    ->setSubtitle('Try: Explain @composer.json')
    ->run();
