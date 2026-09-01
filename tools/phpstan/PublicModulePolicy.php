<?php

declare(strict_types=1);

namespace NeuronTui\PHPStan;

/**
 * Decides which class names a Host Application is allowed to write down.
 *
 * `NeuronTui\Tui` is the interface of Neuron TUI, and two seams are the
 * dependencies a Host Application may supply. The Session provider: the
 * interface to implement, the Session its listing is made of, and the two
 * shipped providers — the in-memory one a Host gets by default and the
 * file-based one it points at a directory. And the Slash commands it mounts:
 * the two interfaces a command may implement — one for a command that expects
 * the Agent to stand still, one for a command that runs while it works — the
 * one they both extend, the Controls, full or fewer, each is handed while it
 * runs, and the choice option passed to the waiting Controls. Every other
 * name under
 * the `NeuronTui` namespace — the internal modules, the test suite, this
 * tooling — carries no stability promise and may be reshaped without notice,
 * so a Host Application may not name any of them.
 *
 * @internal
 */
final class PublicModulePolicy
{
    public const string IDENTIFIER = 'neuronTui.internalUsage';

    /** @var list<string> */
    private const array PUBLIC_MODULES = [
        'NeuronTui\Tui',
        'NeuronTui\Conversation\Command',
        'NeuronTui\Conversation\SlashCommand',
        'NeuronTui\Conversation\RunsWhileWorking',
        'NeuronTui\Conversation\ChoiceOption',
        'NeuronTui\Conversation\Controls',
        'NeuronTui\Conversation\LimitedControls',
        'NeuronTui\Conversation\Commands\Clear',
        'NeuronTui\Conversation\Commands\Help',
        'NeuronTui\Conversation\Commands\Leave',
        'NeuronTui\Conversation\Commands\Sessions',
        'NeuronTui\Session\SessionProvider',
        'NeuronTui\Session\FileSessionProvider',
        'NeuronTui\Session\InMemorySessionProvider',
        'NeuronTui\Session\Session',
    ];

    private const string PREFIX = 'NeuronTui\\';

    public static function isInternal(string $className): bool
    {
        return str_starts_with($className, self::PREFIX)
            && !in_array($className, self::PUBLIC_MODULES, true);
    }

    public static function violationMessage(string $className): string
    {
        return sprintf(
            'A Host Application may only use %s; %s is internal to Neuron TUI.',
            implode(', ', self::PUBLIC_MODULES),
            $className,
        );
    }
}
