<?php

declare(strict_types=1);

namespace NeuronTui\Subagent;

use NeuronAI\Tools\Tool;
use NeuronTui\Conversation\ConversationPort;
use NeuronTui\Conversation\ConversationSourceInterface;

abstract class AbstractSubagentTool extends Tool implements ConversationSourceInterface
{
    public function __construct(
        string $name,
        ?string $description,
        protected readonly Subagents $subagents,
    ) {
        parent::__construct($name, $description);
    }

    public function connect(ConversationPort $conversation): void
    {
        $this->subagents->connect($conversation);
    }
}
