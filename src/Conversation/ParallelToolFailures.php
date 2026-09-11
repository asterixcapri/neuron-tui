<?php

declare(strict_types=1);

namespace NeuronTui\Conversation;

use NeuronAI\Agent\Nodes\ParallelToolNode;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;
use NeuronTui\History\ToolOutcome;
use Throwable;
use UnexpectedValueException;

/**
 * Neuron 3 stops unpacking an already-finished batch at its first unhandled
 * failure. Temporarily defer that failure until every outcome is consumed.
 *
 * This version-specific adapter uses protected access to preserve the existing
 * node, its exact handler, child hooks and run limits. It is never a workflow
 * node itself. A host override of handleError remains in control.
 *
 * @internal
 */
final class ParallelToolFailures extends ParallelToolNode
{
    private ?Throwable $failure = null;

    /** @var array<int, ToolInterface> */
    private array $failedResults = [];

    public function __construct(private readonly ParallelToolNode $node)
    {
        $this->errorHandler = $node->errorHandler;
        $node->errorHandler = function (Throwable $failure, ToolInterface $tool): ?string {
            if ($this->errorHandler !== null) {
                try {
                    $result = ($this->errorHandler)($failure, $tool);

                    if ($result !== null && !is_string($result)) {
                        throw new UnexpectedValueException('The tool error handler must return a string or null.');
                    }

                    return $result;
                } catch (Throwable $handlerFailure) {
                    $failure = $handlerFailure;
                }
            }

            $this->failure ??= $failure;
            $result = ToolOutcome::failed($tool, $failure);
            $this->failedResults[spl_object_id($tool)] = $result;

            if ($tool instanceof Tool) {
                $tool->setResult($result->getResult());
            }

            // Returning null avoids Neuron's legacy attempt to execute a
            // non-Tool implementation again just to assign an error result.
            return null;
        };
    }

    public function result(ToolInterface $tool): ToolInterface
    {
        return $this->failedResults[spl_object_id($tool)] ?? $tool;
    }

    public function throwIfFailed(): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }

    public function restore(): void
    {
        $this->node->errorHandler = $this->errorHandler;
    }
}
