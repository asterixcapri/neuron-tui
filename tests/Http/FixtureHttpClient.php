<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Http;

use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\StreamInterface;
use RuntimeException;

use function array_shift;

final class FixtureHttpClient implements HttpClientInterface
{
    /** @var list<HttpRequest> */
    public array $requests = [];

    /** @var array<string, string> */
    public array $headers = [];

    public string $baseUri = '';
    public float $timeout = 60.0;

    /** @param list<StreamInterface> $streams */
    public function __construct(private array $streams) {}

    public function request(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;

        return new HttpResponse(200, 'normal request');
    }

    public function stream(HttpRequest $request): StreamInterface
    {
        $this->requests[] = $request;

        return array_shift($this->streams) ?? throw new RuntimeException('Unexpected additional HTTP request');
    }

}
