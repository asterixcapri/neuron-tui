<?php

declare(strict_types=1);

namespace NeuronCli\PHPStan;

/**
 * Decides which class names a Host Application is allowed to write down.
 *
 * `NeuronCli\NeuronCli` is the whole public interface of Neuron CLI. Every
 * other name under the `NeuronCli` namespace — the internal modules, the
 * test suite, this tooling — carries no stability promise and may be
 * reshaped without notice, so a Host Application may not name any of them.
 *
 * @internal
 */
final class PublicModulePolicy
{
    public const string IDENTIFIER = 'neuronCli.internalUsage';

    private const string PUBLIC_MODULE = 'NeuronCli\NeuronCli';

    private const string PREFIX = 'NeuronCli\\';

    public static function isInternal(string $className): bool
    {
        return str_starts_with($className, self::PREFIX)
            && $className !== self::PUBLIC_MODULE;
    }

    public static function violationMessage(string $className): string
    {
        return sprintf(
            'A Host Application may only use %s; %s is internal to Neuron CLI.',
            self::PUBLIC_MODULE,
            $className,
        );
    }
}
