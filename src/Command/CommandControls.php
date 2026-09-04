<?php

declare(strict_types=1);

namespace NeuronTui\Command;

use NeuronAI\Agent\Agent;
use NeuronTui\Conversation\ChoiceOption;
use NeuronTui\Session\Sessions;

/** Presentation-independent operations supplied by the active Adapter. */
interface CommandControls
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

    /**
     * Temporary bridge for existing Picker consumers; removed by ticket 05.
     *
     * @param non-empty-list<ChoiceOption> $options
     */
    public function choose(string $title, array $options, ?string $description = null): ?string;
}
