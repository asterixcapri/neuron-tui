<?php

declare(strict_types=1);

namespace NeuronCli\PHPStan;

/**
 * What a Host Application is allowed to name.
 *
 * `NeuronCli\NeuronCli` is the whole public interface of the library.
 * Everything else under the `NeuronCli` namespace is annotated `@internal`
 * and may be reshaped without notice.
 *
 * @internal
 */
final class PublicModule
{
    public const string IDENTIFIER = 'neuronCli.internalUsage';

    private const string PUBLIC_MODULE = 'NeuronCli\NeuronCli';

    private const string PREFIX = 'NeuronCli\\';

    public static function isInternal(string $className): bool
    {
        return str_starts_with($className, self::PREFIX)
            && $className !== self::PUBLIC_MODULE;
    }

    public static function message(string $className): string
    {
        return \sprintf(
            'A Host Application may only use %s; %s is internal to Neuron CLI.',
            self::PUBLIC_MODULE,
            $className,
        );
    }
}
