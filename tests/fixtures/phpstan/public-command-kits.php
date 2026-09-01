<?php

declare(strict_types=1);

use NeuronTui\Conversation\AbstractCommandKit;
use NeuronTui\Conversation\CommandKit;
use NeuronTui\Conversation\Commands\SessionKit;
use NeuronTui\Session\SessionProvider;

final class ConsumerCommandKit extends AbstractCommandKit
{
    protected function provide(): array
    {
        return [];
    }
}

/** @return list<CommandKit> */
function consumerCommandKits(SessionProvider $sessions): array
{
    return [new ConsumerCommandKit(), new SessionKit($sessions)];
}
