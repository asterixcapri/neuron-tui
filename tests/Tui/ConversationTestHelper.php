<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Tui;

use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use Symfony\Component\Tui\Terminal\ScreenBuffer;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class ConversationTestHelper
{
    public static function longHistory(): InMemoryChatHistory
    {
        $history = new InMemoryChatHistory();

        foreach (range(1, 20) as $turn) {
            $history->addMessage(new UserMessage("Question {$turn}"));
            $history->addMessage(new AssistantMessage("Answer {$turn}"));
        }

        return $history;
    }

    /** @return list<string> */
    public static function visibleRows(
        ScreenBuffer $screen,
        VirtualTerminal $terminal,
    ): array {
        $screen->write($terminal->consumeOutput());

        return array_values(array_map(rtrim(...), $screen->getLines()));
    }

    /** @param list<string> $rows */
    public static function contains(array $rows, string $text): bool
    {
        foreach ($rows as $row) {
            if (str_contains($row, $text)) {
                return true;
            }
        }

        return false;
    }
}
