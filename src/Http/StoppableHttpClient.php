<?php

declare(strict_types=1);

namespace NeuronTui\Http;

use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\StreamInterface;

/** Decorates streaming only; Host transport and configuration stay intact. @internal */
final readonly class StoppableHttpClient implements HttpClientInterface
{
    public function __construct(private HttpClientInterface $inner, private ResponseStop $stop)
    {
    }

    public function request(HttpRequest $request): HttpResponse
    {
        return $this->inner->request($request);
    }

    public function stream(HttpRequest $request): StreamInterface
    {
        return $this->stop->wrapStream($this->inner->stream($request));
    }

    public function withBaseUri(string $baseUri): HttpClientInterface
    {
        return new self($this->inner->withBaseUri($baseUri), $this->stop);
    }

    public function withHeaders(array $headers): HttpClientInterface
    {
        return new self($this->inner->withHeaders($headers), $this->stop);
    }

    public function withTimeout(float $timeout): HttpClientInterface
    {
        return new self($this->inner->withTimeout($timeout), $this->stop);
    }
}
