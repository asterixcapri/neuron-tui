<?php

declare(strict_types=1);

namespace NeuronCli\Session;

use NeuronAI\Chat\History\ChatHistoryInterface;

/**
 * The place the Sessions of an Agent live.
 *
 * Neuron AI already saves, reloads and deserializes a conversation, so a
 * store neither writes nor parses one: it decides how a Session is addressed
 * and hands back the History that Neuron CLI installs on the Agent. Keys are
 * minted here and nowhere else, which is why opening without one is how a
 * fresh Session begins.
 *
 * A Host Application that keeps conversations somewhere other than a
 * directory of files implements this and passes it to Neuron CLI.
 */
interface SessionStore
{
    /**
     * Opens the Session with the given key, or a newly minted one.
     */
    public function open(?string $key = null): ChatHistoryInterface;
}
