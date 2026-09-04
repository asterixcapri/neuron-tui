<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Command;

use NeuronAI\Agent\Agent;
use NeuronTui\Command\CommandControlsInterface;
use NeuronTui\Command\Commands;
use NeuronTui\Command\SelectionRequest;
use NeuronTui\Session\Sessions;
use NeuronTui\Storage\InMemoryStorage;

final class FakeCommandControls implements CommandControlsInterface
{
    /** @var list<string> */
    public array $notices = [];

    /** @var list<string> */
    public array $warnings = [];

    /** @var list<string> */
    public array $prompts = [];

    /** @var list<SelectionRequest> */
    public array $selections = [];

    public bool $stopped = false;

    public function __construct(
        public Commands $mounted = new Commands(),
        private Agent $answering = new Agent(),
        private Sessions $collection = new Sessions(new InMemoryStorage()),
    ) {
    }

    public function say(string $text): void
    {
        $this->notices[] = $text;
    }

    public function warn(string $text): void
    {
        $this->warnings[] = $text;
    }

    public function promptAgent(string $prompt): void
    {
        $this->prompts[] = $prompt;
    }

    public function requestSelection(SelectionRequest $request): void
    {
        $this->selections[] = $request;
    }

    public function agent(): Agent
    {
        return $this->answering;
    }

    public function useAgent(Agent $agent): void
    {
        $this->answering = $agent;
    }

    public function commands(): Commands
    {
        return $this->mounted;
    }

    public function sessions(): Sessions
    {
        return $this->collection;
    }

    public function stop(): void
    {
        $this->stopped = true;
    }
}
