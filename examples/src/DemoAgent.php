<?php

declare(strict_types=1);

namespace NeuronTuiDemo;

use NeuronAI\Agent\Agent;
use NeuronAI\Tools\Toolkits\Calendar\CalendarToolkit;
use NeuronAI\Tools\Toolkits\FileSystem\FileSystemToolkit;
use NeuronAI\Tools\Toolkits\FileSystem\GlobPathTool;
use NeuronAI\Tools\Toolkits\Jina\JinaToolkit;

final class DemoAgent extends Agent
{
    public function __construct()
    {
        parent::__construct();

        $this->toolMaxRuns(PHP_INT_MAX);
    }

    protected function tools(): array
    {
        $tools = [
            (new FileSystemToolkit())->exclude([GlobPathTool::class]),
            new CalendarToolkit(),
        ];

        $jinaKey = $_ENV['JINA_API_KEY'] ?? null;

        if (is_string($jinaKey) && $jinaKey !== '') {
            $tools[] = new JinaToolkit($jinaKey);
        }

        return $tools;
    }
}
