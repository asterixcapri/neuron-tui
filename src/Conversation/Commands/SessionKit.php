<?php

declare(strict_types=1);

namespace NeuronCli\Conversation\Commands;

use NeuronCli\Conversation\AbstractCommandKit;
use NeuronCli\Session\SessionProvider;

/**
 * The commands Neuron CLI ships for the Sessions, mounted in one line.
 *
 * Both of them need to know where the conversations live, and they have to be
 * told the same place or they disagree about which Sessions exist. So the
 * provider is named here once and reaches both, which is the whole reason
 * this kit exists.
 *
 * An application in which conversations are not thrown away mounts it without
 * `Clear`; one that only wants to return to an earlier conversation keeps
 * `Sessions` alone. Names are the commands' own business, so a kit is the
 * short way in and writing the two commands out by hand remains the way to
 * rename them.
 */
final class SessionKit extends AbstractCommandKit
{
    /**
     * @param SessionProvider $sessions the place the conversations live
     */
    public function __construct(private readonly SessionProvider $sessions)
    {
    }

    protected function provide(): array
    {
        return [
            new Clear($this->sessions),
            new Sessions($this->sessions),
        ];
    }
}
