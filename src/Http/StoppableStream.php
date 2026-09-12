<?php

declare(strict_types=1);

namespace NeuronTui\Http;

use NeuronAI\HttpClient\StreamInterface;

/** Signals ordinary EOF so the provider can finalize its own response. @internal */
final class StoppableStream implements StreamInterface
{
    private bool $closed = false;

    public function __construct(private readonly StreamInterface $inner, private readonly StopRequest $request)
    {
    }

    public function eof(): bool
    {
        if ($this->closed || $this->inner->eof()) {
            return true;
        }

        if ($this->request->checkpoint()) {
            $this->close();
            $this->request->apply();

            return true;
        }

        return $this->inner->eof();
    }

    public function read(int $length): string
    {
        return $this->closed ? '' : $this->inner->read($length);
    }

    public function readLine(): string
    {
        return $this->closed ? '' : $this->inner->readLine();
    }

    public function close(): void
    {
        if (!$this->closed) {
            $this->inner->close();
            $this->closed = true;
        }
    }
}
