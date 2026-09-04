<?php

declare(strict_types=1);

namespace NeuronTui\Subagent;

use Amp\Cancellation;
use Amp\Parallel\Context\Context;
use Amp\Parallel\Context\ContextFactory;
use Amp\Parallel\Context\ProcessContext;
use Amp\Parallel\Context\ProcessContextFactory;
use Amp\ByteStream\ReadableStream;

use function Amp\async;

/** @internal */
final class SilentProcessContextFactory implements ContextFactory
{
    private readonly ProcessContextFactory $contexts;

    public function __construct()
    {
        $this->contexts = new ProcessContextFactory();
    }

    /** @return Context<mixed, mixed, mixed> */
    public function start(
        string|array $script,
        ?Cancellation $cancellation = null,
    ): Context {
        $context = $this->contexts->start($script, $cancellation);

        $context->getStdout()->unreference();
        $context->getStderr()->unreference();
        async(self::discard(...), $context->getStdout())->ignore();
        async(self::discard(...), $context->getStderr())->ignore();

        return $context;
    }

    private static function discard(ReadableStream $stream): void
    {
        while ($stream->read() !== null) {
        }
    }
}
