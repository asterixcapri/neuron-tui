<?php

declare(strict_types=1);

namespace NeuronTui\Session;

use InvalidArgumentException;
use NeuronAI\Agent\Agent;
use NeuronInteraction\Session\Session;
use NeuronInteraction\Session\SessionTitleGenerator;
use Throwable;

use function Amp\async;

/** @internal Generates a title after successful turns within a per-Session attempt limit. */
final class SessionTitleGeneration
{
    /** @var array<string, true> */
    private array $running = [];

    public function __construct(private readonly int $maxAttempts = 3)
    {
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('The title generation attempt limit must be positive.');
        }
    }

    public function schedule(Session $session, Agent $agent): void
    {
        $key = $session->getKey();
        if ($session->title() !== null || isset($this->running[$key])) {
            return;
        }

        try {
            $attempts = filter_var(
                $session->getMetadata()['titleGenerationAttempts'] ?? '0',
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 0]],
            );
            if ($attempts === false || $attempts >= $this->maxAttempts) {
                return;
            }
            $session->setMetadata('titleGenerationAttempts', (string) ($attempts + 1));
            $provider = clone $agent->resolveProvider();
        } catch (Throwable) {
            return;
        }

        $this->running[$key] = true;
        async(function () use ($session, $key, $provider): void {
            try {
                $generator = new SessionTitleGenerator($provider, $session);
                $title = $generator->generate();
                if ($title !== null && $session->title() === null) {
                    $session->setTitle($title);
                }
            } catch (Throwable) {
                // Optional title generation must not interrupt the conversation; a later turn can retry within the attempt limit.
            } finally {
                unset($this->running[$key]);
            }
        });
    }
}
