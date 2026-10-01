<?php

declare(strict_types=1);

use NeuronAI\HttpClient\Amp\AmpHttpClient;
use NeuronAI\HttpClient\StoppableHttpClient;
use NeuronChatCore\Command\ClearCommand;
use NeuronChatCore\Command\Commands;
use NeuronChatCore\Command\HelpCommand;
use NeuronChatCore\Command\LeaveCommand;
use NeuronChatCore\Command\ResumeCommand;
use NeuronChatCore\Configuration\ConfigurationStore;
use NeuronChatCore\Conversation\ConversationRuntime as CoreRuntime;
use NeuronChatCore\InputHistory\InputHistory;
use NeuronChatCore\Interruption\StopSignal;
use NeuronChatCore\Message\UserMessageProcessors;
use NeuronChatCore\Session\SessionStore;
use NeuronChatCore\Storage\FileStorage;
use NeuronChatCore\Storage\InMemoryStorage;
use NeuronTui\Tui;
use NeuronTuiDemo\AIProviderFactory;
use NeuronTuiDemo\DemoAgent;
use NeuronTuiDemo\FileReferenceProcessor;
use NeuronTuiDemo\ModelCommand;
use Symfony\Component\Dotenv\Dotenv;

use function Amp\delay;

require_once __DIR__ . '/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/../.env');

$storage = new FileStorage(__DIR__ . '/../.storage');
$sessionStore = new SessionStore($storage, 'local');
$inputHistory = new InputHistory($storage);

$configurationStore = new ConfigurationStore($storage, 'local');
$modelId = $configurationStore->read('model', 'openai:gpt-5.4-nano');

$stopSignal = new StopSignal(new InMemoryStorage(), 'full');

$httpClient = new StoppableHttpClient(
    client: new AmpHttpClient(),
    shouldStop: $stopSignal->stopCallback(
        onPoll: function (): void {
            delay(0);
        },
    ),
);

$agent = DemoAgent::make();
$agent->setAiProvider(AIProviderFactory::create($modelId, $httpClient));
$session = $sessionStore->create();

$commands = (new Commands())->addCommand([
    new ClearCommand(),
    new ResumeCommand(),
    new ModelCommand($httpClient),
    new LeaveCommand(),
    new HelpCommand(),
]);

$userMessageProcessors = (new UserMessageProcessors())->addProcessor([
    new FileReferenceProcessor(__DIR__ . '/..'),
]);

Tui::make(
    new CoreRuntime($agent, $sessionStore, session: $session, stopSignal: $stopSignal, userMessageProcessors: $userMessageProcessors),
    commands: $commands,
    configurationStore: $configurationStore,
    inputHistory: $inputHistory
)
    ->setFiglet('NeuronTUI')
    ->setTitle('Neuron TUI Demo')
    ->setSubtitle('Powered by Neuron AI')
    ->run();
