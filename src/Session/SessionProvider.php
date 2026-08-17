<?php

declare(strict_types=1);

namespace NeuronCli\Session;

use NeuronAI\Chat\History\ChatHistoryInterface;

/**
 * Where the Sessions of an Agent come from.
 *
 * Neuron AI already saves, reloads and deserializes a conversation, so a
 * provider neither writes nor parses one: it decides how a Session is
 * addressed, says which Sessions exist, and hands back the History that
 * Neuron CLI installs on the Agent. Keys are minted here and nowhere else,
 * so the only key `open()` can be given is one `create()` or `list()` handed
 * out.
 *
 * A Host Application that keeps conversations somewhere other than a
 * directory of files implements this and passes it to Neuron CLI.
 */
interface SessionProvider
{
    /**
     * Mints a Session nobody has written to yet.
     */
    public function create(): Session;

    /**
     * The Sessions a person can return to, most recently used first.
     *
     * Ordering is the provider's own, because only it knows when a Session
     * was last written to. A Session that never received a message is not
     * one a person can return to, so it is left out.
     *
     * @return list<Session>
     */
    public function list(): array;

    /**
     * Opens the Session with the given key.
     */
    public function open(string $key): ChatHistoryInterface;
}
