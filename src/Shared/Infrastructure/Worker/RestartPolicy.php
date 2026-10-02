<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker;

use Closure;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use UnexpectedValueException;

/** Tracks one worker's restart budget; exhaustion requires an explicit new policy. */
#[Exclude]
final class RestartPolicy
{
    /** @var list<float> */
    private array $restartTimes = [];

    private ?float $lastTime = null;
    private bool $exhausted = false;

    /** @var Closure(): float */
    private readonly Closure $random;

    /** @param (Closure(): float)|null $random Returns a sample between zero and one, inclusive. */
    public function __construct(
        private readonly int $maxRestarts = 5,
        private readonly float $restartWindowSeconds = 60.0,
        private readonly float $initialDelaySeconds = 0.25,
        private readonly float $maxDelaySeconds = 10.0,
        private readonly float $jitterRatio = 0.2,
        ?Closure $random = null,
    ) {
        if ($maxRestarts < 1
            || !is_finite($restartWindowSeconds) || $restartWindowSeconds <= 0
            || !is_finite($initialDelaySeconds) || $initialDelaySeconds <= 0
            || !is_finite($maxDelaySeconds) || $maxDelaySeconds < $initialDelaySeconds
            || !is_finite($jitterRatio) || $jitterRatio < 0 || $jitterRatio >= 1
        ) {
            throw new InvalidArgumentException('Restart policy requires a positive budget, window and delays, a cap at least as large as the initial delay, and jitter in [0, 1).');
        }

        $this->random = $random ?? static fn (): float => random_int(0, 1_000_000) / 1_000_000;
    }

    /**
     * Reserve a restart attempt and return its delay, or null after budget exhaustion.
     * The caller supplies monotonic seconds, for example hrtime(true) / 1_000_000_000.
     */
    public function nextDelay(float $monotonicNow): ?float
    {
        if (!is_finite($monotonicNow) || $monotonicNow < 0 || ($this->lastTime !== null && $monotonicNow < $this->lastTime)) {
            throw new InvalidArgumentException('Restart time must be finite, nonnegative and monotonic.');
        }
        $this->lastTime = $monotonicNow;
        if ($this->exhausted) {
            return null;
        }

        $windowStart = $monotonicNow - $this->restartWindowSeconds;
        $recentRestarts = array_values(array_filter($this->restartTimes, static fn (float $time): bool => $time > $windowStart));
        if (count($recentRestarts) >= $this->maxRestarts) {
            $this->exhausted = true;
            return null;
        }

        $sample = ($this->random)();
        if (!is_finite($sample) || $sample < 0 || $sample > 1) {
            throw new UnexpectedValueException('Restart jitter sample must be finite and in [0, 1].');
        }
        $baseDelay = min($this->maxDelaySeconds, $this->initialDelaySeconds * (2 ** count($recentRestarts)));
        $delay = min($this->maxDelaySeconds, $baseDelay * (1 + $this->jitterRatio * (2 * $sample - 1)));

        $recentRestarts[] = $monotonicNow;
        $this->restartTimes = $recentRestarts;

        return $delay;
    }

    public function isExhausted(): bool
    {
        return $this->exhausted;
    }
}
