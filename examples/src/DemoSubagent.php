<?php

declare(strict_types=1);

namespace NeuronTuiDemo;

final class DemoSubagent extends DemoAgent
{
    protected function tools(): array
    {
        return $this->demoTools();
    }
}
