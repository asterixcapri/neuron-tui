<?php

declare(strict_types=1);

namespace NeuronTui\Command;

use DateTimeImmutable;
use NeuronTui\Conversation\ChoiceOption;
use NeuronTui\Conversation\Controls;
use NeuronTui\Conversation\SessionMetadata;
use NeuronTui\Session\SessionProvider;

/**
 * Offers the stored Sessions so a person can resume one.
 *
 * One of the commands Neuron TUI ships: a Host Application mounts it the way
 * it mounts one of its own, under `/resume` or under whatever name it
 * prefers.
 *
 * A list with nothing in it is not worth entering, so it is said in the
 * conversation instead. The Sessions become ChoiceOptions here, while their
 * keys and titles are still something known.
 */
final readonly class ResumeCommand implements CommandInterface
{
    /**
     * @param SessionProvider $sessions the place the conversations live
     * @param string          $name     the name it answers to, slash included
     */
    public function __construct(
        private SessionProvider $sessions,
        private string $name = '/resume',
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function describe(): string
    {
        return 'Lets you choose a stored Session to resume.';
    }

    public function run(Controls $controls, string $arguments): void
    {
        $sessions = $this->sessions->list();

        if ($sessions === []) {
            $controls->warn('There is no earlier Session to return to yet.');

            return;
        }

        $options = [];
        $now = new DateTimeImmutable();

        foreach ($sessions as $session) {
            $options[] = new ChoiceOption(
                $session->key,
                $session->title,
                SessionMetadata::format($session, $now),
            );
        }

        $chosen = $controls->choose('Sessions', $options);

        if ($chosen === null) {
            return;
        }

        $controls->agent()->setChatHistory($this->sessions->resume($chosen));
    }
}
