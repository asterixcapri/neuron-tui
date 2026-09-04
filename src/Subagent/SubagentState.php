<?php

declare(strict_types=1);

namespace NeuronTui\Subagent;

enum SubagentState: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Idle = 'idle';
    case Failed = 'failed';
}
