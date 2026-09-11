<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Fixtures;

use NeuronAI\Tools\Tool;
use RuntimeException;

/** A serializable real child operation with a bounded concurrency barrier. */
final class ParallelBatchTool extends Tool
{
    public function __construct(string $name, private readonly string $directory, private readonly bool $fails = false)
    {
        parent::__construct($name);
        $this->setCallId($name . '-id')->setInputs(['value' => $name]);
    }

    public function __invoke(): string
    {
        file_put_contents($this->directory . '/' . $this->getName() . '.started', (string) getmypid(), FILE_APPEND);
        $deadline = microtime(true) + 2;

        while (count(glob($this->directory . '/*.started') ?: []) < 3) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('The real parallel batch did not start all three children.');
            }

            usleep(1000);
        }

        // Ensure the terminal-input polling timer is due on the first result.
        usleep(3000);
        file_put_contents($this->directory . '/' . $this->getName() . '.settled', 'once', FILE_APPEND);

        if ($this->fails) {
            throw new RuntimeException($this->getName() . ' really failed.');
        }

        return $this->getName() . ' really completed.';
    }
}
