<?php

declare(strict_types=1);

namespace NeuronTui\Http;

use LogicException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\StreamInterface;

/**
 * Experimental, opt-in HTTP response stop shared by a Host provider and its TUI.
 * One controller belongs to one conversation; it cannot serve concurrent Turns.
 */
final class ResponseStop
{
    private ?StopRequest $request = null;

    public function httpClient(HttpClientInterface $inner): HttpClientInterface
    {
        return new StoppableHttpClient($inner, $this);
    }

    /** @internal Called by the TUI when a Turn is accepted for execution. */
    public function begin(): void
    {
        if ($this->request !== null) {
            throw new LogicException('ResponseStop is already attached to an active Turn.');
        }

        $this->request = new StopRequest();
    }

    /** Returns true only when this is the first request for the active Turn. */
    public function request(): bool
    {
        return $this->request?->request() ?? false;
    }

    /** @internal Finalizes this Turn and reports whether an HTTP stream stopped. */
    public function finish(): bool
    {
        $stopped = $this->request?->finish() ?? false;
        $this->request = null;

        return $stopped;
    }

    /** @internal Binds the stream to this Turn, never to a later request. */
    public function wrapStream(StreamInterface $stream): StreamInterface
    {
        return $this->request === null ? $stream : new StoppableStream($stream, $this->request);
    }
}
