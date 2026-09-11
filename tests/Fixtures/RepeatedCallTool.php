<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Fixtures;

use NeuronAI\Tools\Tool;
use RuntimeException;

/** Serializable calls of one tool whose provider repeats or omits call ids. */
final class RepeatedCallTool extends Tool
{
    public function __construct(
        private readonly int $value,
        private readonly string $directory,
        private readonly bool $fails = false,
    ) {
        parent::__construct('lookup');
        $this->setInputs(['value' => $value]);
    }

    public function __invoke(): string
    {
        // The first requested call settles last, so Neuron yields the same
        // name/id results out of request order after deserializing the batch.
        if ($this->value === 1) {
            usleep(40000);
        }

        file_put_contents($this->directory . '/' . $this->value . '.settled', (string) getmypid(), FILE_APPEND);

        if ($this->fails) {
            throw new RuntimeException('Lookup ' . $this->value . ' failed.');
        }

        return 'Lookup ' . $this->value . ' completed.';
    }
}
