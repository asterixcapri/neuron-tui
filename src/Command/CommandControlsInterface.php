<?php

declare(strict_types=1);

namespace NeuronTui\Command;

use NeuronAI\Agent\Agent;
use NeuronTui\Session\Sessions;

/** Presentation-independent operations supplied by the active Adapter. */
interface CommandControlsInterface
{
    public function say(string $text): void;

    public function warn(string $text): void;

    /** Submit a prompt to the Adapter's Agent flow without receiving its answer. */
    public function promptAgent(string $prompt): void;

    /** Request a later invocation with the chosen value, then return immediately. */
    public function requestSelection(SelectionRequest $request): void;

    public function agent(): Agent;

    public function useAgent(Agent $agent): void;

    public function commands(): Commands;

    public function sessions(): Sessions;

    public function stop(): void;
}
