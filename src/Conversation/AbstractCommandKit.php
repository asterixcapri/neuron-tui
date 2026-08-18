<?php

declare(strict_types=1);

namespace NeuronCli\Conversation;

/**
 * A kit whose members are built once and then sieved.
 *
 * What a kit is made of is the one thing each kit says for itself, in
 * `provide()`; leaving members out is the same work every time, so it is
 * written here. A Host Application writing a kit of its own extends this and
 * names its commands.
 *
 * Both sieves answer with another kit and leave the one asked as it was, so a
 * kit held in a variable can be mounted twice with different members. What is
 * kept is decided by class, and a command of a class deriving from a named
 * one is that command as far as this is concerned.
 */
abstract class AbstractCommandKit implements CommandKit
{
    /**
     * The classes to leave out.
     *
     * @var list<class-string>
     */
    private array $excluded = [];

    /**
     * The classes to keep, or nothing at all when everything is kept.
     *
     * @var list<class-string>|null
     */
    private ?array $kept = null;

    /**
     * Everything this kit is made of, before anything is left out.
     *
     * @return list<SlashCommand>
     */
    abstract protected function provide(): array;

    public function commands(): array
    {
        $commands = [];

        foreach ($this->provide() as $command) {
            if ($this->keeps($command)) {
                $commands[] = $command;
            }
        }

        return $commands;
    }

    public function exclude(array $classes): static
    {
        $kit = clone $this;
        $kit->excluded = [...$this->excluded, ...$classes];

        return $kit;
    }

    public function only(array $classes): static
    {
        $kit = clone $this;
        $kit->kept = [...$this->kept ?? [], ...$classes];

        return $kit;
    }

    /**
     * Whether the command survives what was asked to be left out.
     *
     * Being left out wins over being kept, so a class named to both is gone:
     * the two asked for together can only have meant that.
     */
    private function keeps(SlashCommand $command): bool
    {
        foreach ($this->excluded as $class) {
            if ($command instanceof $class) {
                return false;
            }
        }

        if ($this->kept === null) {
            return true;
        }

        foreach ($this->kept as $class) {
            if ($command instanceof $class) {
                return true;
            }
        }

        return false;
    }
}
