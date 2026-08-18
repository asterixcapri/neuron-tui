<?php

declare(strict_types=1);

namespace NeuronCli\Conversation;

/**
 * A group of Slash commands mounted in one line.
 *
 * A kit carries whatever its members need to work — the Session provider, for
 * the kit this library ships — so a Host Application names that thing once
 * instead of once per command. What comes out is commands and nothing else: a
 * kit is unrolled when the terminal is built, and afterwards a command that
 * arrived in a kit and one named on its own are the same thing, indexed in
 * the same list under the same rules.
 *
 * A kit can be taken with some of its members left out, or with only the
 * named ones kept, so `/sessions` without `/clear` costs no command of one's
 * own. Both answer with a kit of their own and leave the one asked untouched,
 * so the same kit can be mounted twice, differently.
 *
 * The name avoids "toolkit", which in Neuron AI means a group of tools for
 * the model.
 */
interface CommandKit
{
    /**
     * The commands this kit mounts, once what was left out is gone.
     *
     * @return list<SlashCommand|RunsWhileWorking>
     */
    public function commands(): array;

    /**
     * The same kit without the commands of the named classes.
     *
     * @param list<class-string> $classes
     */
    public function exclude(array $classes): static;

    /**
     * The same kit with only the commands of the named classes.
     *
     * @param list<class-string> $classes
     */
    public function only(array $classes): static;
}
