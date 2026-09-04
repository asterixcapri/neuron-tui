<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Examples;

use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronTuiDemo\DemoAgent;
use NeuronTuiDemo\DemoSubagent;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once __DIR__.'/../../examples/src/DemoAgent.php';
require_once __DIR__.'/../../examples/src/DemoSubagent.php';

final class DemoAgentTest extends TestCase
{
    public function testProviderCredentialsCanComeFromTheProcessEnvironment(): void
    {
        $processValue = getenv('OPENAI_API_KEY');
        $hadEnvValue = array_key_exists('OPENAI_API_KEY', $_ENV);
        $envValue = $_ENV['OPENAI_API_KEY'] ?? null;

        putenv('OPENAI_API_KEY=worker-visible-key');
        unset($_ENV['OPENAI_API_KEY']);

        try {
            $provider = (new ReflectionMethod(DemoAgent::class, 'provider'))
                ->invoke(new DemoSubagent());

            self::assertInstanceOf(OpenAIResponses::class, $provider);
        } finally {
            $processValue === false
                ? putenv('OPENAI_API_KEY')
                : putenv('OPENAI_API_KEY='.$processValue);

            if ($hadEnvValue) {
                $_ENV['OPENAI_API_KEY'] = $envValue;
            } else {
                unset($_ENV['OPENAI_API_KEY']);
            }
        }
    }
}
