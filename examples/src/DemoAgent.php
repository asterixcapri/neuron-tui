<?php

declare(strict_types=1);

namespace NeuronTuiDemo;

use InvalidArgumentException;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\HttpClient\AmpHttpClient;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronAI\Tools\Toolkits\Calendar\CalendarToolkit;
use NeuronAI\Tools\Toolkits\FileSystem\FileSystemToolkit;
use NeuronAI\Tools\Toolkits\FileSystem\GlobPathTool;
use NeuronAI\Tools\Toolkits\Jina\JinaToolkit;
use NeuronInteraction\Agent\ConfiguredAgentInterface;
use NeuronInteraction\Configuration\ConfigurationStore;
use NeuronInteraction\Session\SessionStore;
use NeuronInteraction\Storage\FileStorage;
use RuntimeException;

final class DemoAgent extends Agent implements ConfiguredAgentInterface
{
    public static function createAgent(ConfigurationStore $configurationStore): static
    {
        $configuration = $configurationStore->read('agent')
            ?? throw new RuntimeException('Agent configuration "agent" is missing.');
        $model = $configuration->get('model');
        if (!is_string($model)) {
            throw new InvalidArgumentException('The demo configuration requires a provider:model identifier.');
        }
        ModelCommand::validateModel($model);

        $sessionStoragePath = $configuration->get('sessionStoragePath', dirname(__DIR__) . '/.storage');
        if (!is_string($sessionStoragePath) || $sessionStoragePath === '') {
            throw new InvalidArgumentException('The demo session storage path must be a non-empty string.');
        }

        return (new static(new SessionStore(
            new FileStorage($sessionStoragePath),
            $configuration->getUserId(),
        )))->setModelId($model);
    }

    private string $modelId = 'openai:gpt-5.4-nano';

    public function __construct(private readonly ?SessionStore $sessionStore = null)
    {
        parent::__construct();

        $this->toolMaxRuns(PHP_INT_MAX);
    }

    public function setModelId(string $modelId): static
    {
        $this->modelId = $modelId;

        return $this;
    }

    protected function chatHistory(): ChatHistoryInterface
    {
        // Only startup needs this fallback. Clear and Resume assign History
        // before it is requested, so construction itself creates no Session.
        return $this->sessionStore?->create() ?? parent::chatHistory();
    }

    protected function provider(): AIProviderInterface
    {
        [$provider, $model] = explode(':', $this->modelId, 2);

        if ($provider === 'openai') {
            $key = $_ENV['OPENAI_API_KEY'] ?? null;

            if (!is_string($key) || $key === '') {
                throw new RuntimeException('OPENAI_API_KEY not configured');
            }

            return new OpenAIResponses(
                key: $key,
                model: $model,
                httpClient: new AmpHttpClient(),
            );
        } elseif ($provider === 'anthropic') {
            $key = $_ENV['ANTHROPIC_API_KEY'] ?? null;

            if (!is_string($key) || $key === '') {
                throw new RuntimeException('ANTHROPIC_API_KEY not configured');
            }

            return new Anthropic(
                key: $key,
                model: $model,
                httpClient: new AmpHttpClient(),
            );
        } else {
            throw new RuntimeException("Unknown provider: {$provider}.");
        }
    }

    protected function tools(): array
    {
        $tools = [
            (new FileSystemToolkit())->exclude([GlobPathTool::class]),
            new CalendarToolkit(),
        ];

        $jinaKey = $_ENV['JINA_API_KEY'] ?? null;

        if (is_string($jinaKey) && $jinaKey !== '') {
            $tools[] = new JinaToolkit($jinaKey);
        }

        return $tools;
    }
}
