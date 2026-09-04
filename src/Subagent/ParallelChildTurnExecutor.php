<?php

declare(strict_types=1);

namespace NeuronTui\Subagent;

use Amp\Future;
use Amp\Parallel\Worker\ContextWorkerFactory;
use Amp\Parallel\Worker\ContextWorkerPool;
use Composer\Autoload\ClassLoader;
use NeuronAI\Agent\Agent;
use RuntimeException;

/** @internal */
final class ParallelChildTurnExecutor implements ChildTurnExecutorInterface
{
    private readonly ContextWorkerPool $workers;

    public function __construct()
    {
        $factory = new ContextWorkerFactory(
            self::hostAutoloader(),
            new SilentProcessContextFactory(),
        );
        $this->workers = new ContextWorkerPool(1, $factory);
    }

    public function execute(
        string $agentClass,
        string $message,
        array $history,
    ): Future {
        return $this->workers
            ->submit(new ChildTurnTask($agentClass, $message, $history))
            ->getFuture();
    }

    private static function hostAutoloader(): string
    {
        foreach (ClassLoader::getRegisteredLoaders() as $vendor => $loader) {
            if ($loader->findFile(self::class) !== false) {
                $autoload = $vendor.'/autoload.php';

                if (is_file($autoload)) {
                    return $autoload;
                }
            }
        }

        throw new RuntimeException(
            'Unable to locate the Host Application Composer autoloader.',
        );
    }
}
