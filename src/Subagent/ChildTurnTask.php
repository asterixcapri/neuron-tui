<?php

declare(strict_types=1);

namespace NeuronTui\Subagent;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use JsonException;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\UserMessage;

/**
 * The serializable description of one child turn. No live Agent, provider,
 * History, tool callback or credential crosses this process boundary.
 *
 * @implements Task<ChildTurnResult, never, never>
 */
final readonly class ChildTurnTask implements Task
{
    public function __construct(private ChildTurn $turn) {}

    /** @throws JsonException */
    public function run(Channel $channel, Cancellation $cancellation): ChildTurnResult
    {
        $cancellation->throwIfRequested();

        $agent = $this->turn->agentClass::make();
        $history = new SerializedChatHistory($this->turn->history);
        $agent->setChatHistory($history);

        $reply = $agent->chat(new UserMessage($this->turn->message))->getMessage();
        $cancellation->throwIfRequested();

        $contents = implode('', array_map(
            static fn (TextContent $block): string => $block->content,
            $reply->getTextBlocks(),
        ));

        return new ChildTurnResult(
            $contents,
            self::plainHistory($history->jsonSerialize()),
        );
    }

    /**
     * @param array<int, mixed> $history
     * @return list<array<string, mixed>>
     * @throws JsonException
     */
    private static function plainHistory(array $history): array
    {
        $json = json_encode($history, JSON_THROW_ON_ERROR);

        /** @var list<array<string, mixed>> */
        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }
}
