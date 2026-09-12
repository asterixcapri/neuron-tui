<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Http;

use Closure;
use NeuronAI\HttpClient\StreamInterface;

final class FixtureStream implements StreamInterface
{
    public int $closes = 0;
    private int $offset = 0;

    /** @param (Closure(string): void)|null $onRead */
    public function __construct(private readonly string $body, private readonly ?Closure $onRead = null)
    {
    }

    public function eof(): bool
    {
        return $this->closes > 0 || $this->offset >= strlen($this->body);
    }

    public function read(int $length): string
    {
        $text = substr($this->body, $this->offset, $length);
        $this->offset += strlen($text);
        ($this->onRead)?->__invoke($text);

        return $text;
    }

    public function readLine(): string
    {
        $newline = strpos($this->body, "\n", $this->offset);

        return $this->read($newline === false ? strlen($this->body) - $this->offset : $newline + 1 - $this->offset);
    }

    public function close(): void
    {
        ++$this->closes;
    }
}
