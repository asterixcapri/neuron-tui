<?php

declare(strict_types=1);

namespace NeuronTui\Subagent;

use Amp\Cancellation;
use Amp\Future;
use Amp\Parallel\Worker\ContextWorkerFactory;
use Amp\Parallel\Worker\ContextWorkerPool;
use Composer\Autoload\ClassLoader;
use NeuronAI\Agent\Agent;
use RuntimeException;

/** @internal */
final class ParallelChildTurnExecutor implements ChildTurnExecutorInterface
{
    private ?ContextWorkerPool $workers = null;

    public function __construct(private readonly int $concurrency = 4)
    {
    }

    public function execute(
        string $agentClass,
        string $message,
        array $history,
        Cancellation $cancellation,
    ): Future {
        return $this->workers()
            ->submit(
                new ChildTurnTask($agentClass, $message, $history),
                $cancellation,
            )
            ->getFuture();
    }

    public function cancel(): void
    {
        $this->workers?->kill();
        $this->workers = null;
    }

    private function workers(): ContextWorkerPool
    {
        if ($this->workers instanceof ContextWorkerPool) {
            return $this->workers;
        }

        $factory = new ContextWorkerFactory(
            self::hostAutoloader(),
            new SilentProcessContextFactory(),
        );

        return $this->workers = new ContextWorkerPool(
            $this->concurrency,
            $factory,
        );
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
