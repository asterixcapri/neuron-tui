<?php

declare(strict_types=1);

namespace NeuronCli\Conversation\Commands;

use NeuronCli\Conversation\Controls;
use NeuronCli\Conversation\SlashCommand;
use NeuronCli\Session\SessionProvider;

/**
 * Starts a new Session, leaving the one on screen where it is stored.
 *
 * One of the commands Neuron CLI ships: a Host Application mounts it the way
 * it mounts one of its own, under `/clear` or under whatever name it prefers.
 *
 * Minting a Session and opening it are the provider's two separate
 * operations, and a new Session is both: the key comes back from the provider
 * and goes straight back to it. Nothing here ever deletes what it replaced.
 */
final readonly class Clear implements SlashCommand
{
    /**
     * @param SessionProvider $sessions the place the conversations live
     * @param string          $name     the name it answers to, slash included
     */
    public function __construct(
        private SessionProvider $sessions,
        private string $name = '/clear',
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function describe(): string
    {
        return 'Starts a new Session, leaving the current one stored.';
    }

    public function run(Controls $controls, string $arguments): void
    {
        $controls->agent()->setChatHistory(
            $this->sessions->open($this->sessions->create()->key),
        );
    }
}
