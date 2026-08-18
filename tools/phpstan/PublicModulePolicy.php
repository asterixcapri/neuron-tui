<?php

declare(strict_types=1);

namespace NeuronCli\PHPStan;

/**
 * Decides which class names a Host Application is allowed to write down.
 *
 * `NeuronCli\NeuronCli` is the interface of Neuron CLI, and two seams are the
 * dependencies a Host Application may supply. The Session provider: the
 * interface to implement, the Session its listing is made of, and the two
 * shipped providers — the in-memory one a Host gets by default and the
 * file-based one it points at a directory. And the Slash commands it mounts:
 * the interface a command implements, the one it extends, and the Controls a
 * command is handed while it runs. Every other name under
 * the `NeuronCli` namespace — the internal modules, the test suite, this
 * tooling — carries no stability promise and may be reshaped without notice,
 * so a Host Application may not name any of them.
 *
 * @internal
 */
final class PublicModulePolicy
{
    public const string IDENTIFIER = 'neuronCli.internalUsage';

    /** @var list<string> */
    private const array PUBLIC_MODULES = [
        'NeuronCli\NeuronCli',
        'NeuronCli\Conversation\Command',
        'NeuronCli\Conversation\SlashCommand',
        'NeuronCli\Conversation\Controls',
        'NeuronCli\Conversation\Commands\Clear',
        'NeuronCli\Conversation\Commands\Help',
        'NeuronCli\Conversation\Commands\Leave',
        'NeuronCli\Conversation\Commands\Sessions',
        'NeuronCli\Session\SessionProvider',
        'NeuronCli\Session\FileSessionProvider',
        'NeuronCli\Session\InMemorySessionProvider',
        'NeuronCli\Session\Session',
    ];

    private const string PREFIX = 'NeuronCli\\';

    public static function isInternal(string $className): bool
    {
        return str_starts_with($className, self::PREFIX)
            && !in_array($className, self::PUBLIC_MODULES, true);
    }

    public static function violationMessage(string $className): string
    {
        return sprintf(
            'A Host Application may only use %s; %s is internal to Neuron CLI.',
            implode(', ', self::PUBLIC_MODULES),
            $className,
        );
    }
}
