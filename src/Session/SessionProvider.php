<?php

declare(strict_types=1);

namespace NeuronCli\Session;

use NeuronAI\Chat\History\ChatHistoryInterface;

/**
 * Where the Sessions of an Agent come from.
 *
 * Neuron AI already saves, reloads and deserializes a conversation, so a
 * provider neither writes nor parses one: it starts a new Session, says which
 * Sessions exist, and hands back the History of one being resumed. Keys are
 * minted and consumed behind this seam; a caller only carries one from
 * `list()` to `resume()` when a person chooses an existing Session.
 *
 * A Host Application that keeps conversations somewhere other than a
 * directory of files implements this and passes it to Neuron CLI.
 */
interface SessionProvider
{
    /**
     * Starts a new Session and returns its empty History.
     */
    public function start(): ChatHistoryInterface;

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
     * Resumes the existing Session with the given key.
     *
     * A key names no new Session here: only `start()` starts one.
     */
    public function resume(string $key): ChatHistoryInterface;
}
