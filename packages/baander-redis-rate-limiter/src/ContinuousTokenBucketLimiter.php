<?php

declare(strict_types=1);

namespace Baander\RedisRateLimiter;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\RateLimiter\Exception\MaxWaitDurationExceededException;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\Reservation;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

/**
 * The `token_bucket` policy of the atomic Redis limiters. Each token refills a fixed time after
 * the one before it, counted from when it was taken, so with a burst of one every turn starts a
 * full refill time after the previous turn.
 *
 * Symfony's own token bucket refills whole intervals counted from when the bucket was created
 * and measures waits from the moment of asking. A turn taken late in one interval and another
 * taken early in the next can then come milliseconds apart, which a strict per-second limit
 * like MusicBrainz's rejects.
 */
#[Exclude]
final readonly class ContinuousTokenBucketLimiter implements LimiterInterface
{
    public function __construct(
        private string $id,
        private int $burst,
        private float $secondsPerToken,
        private StorageInterface $storage,
    ) {
    }

    /** @param array<string, mixed> $config a validated `token_bucket` configuration */
    public static function fromConfig(array $config, string $id, StorageInterface $storage): self
    {
        $seconds = RateLimiterInterval::seconds($config['rate']['interval']) / ($config['rate']['amount'] ?? 1);

        return new self($id, $config['limit'], $seconds, $storage);
    }

    #[\Override]
    public function reserve(int $tokens = 1, ?float $maxTime = null): Reservation
    {
        if ($tokens > $this->burst) {
            throw new \InvalidArgumentException(sprintf('Cannot reserve more tokens (%d) than the burst size of the rate limiter (%d).', $tokens, $this->burst));
        }
        $now = microtime(true);
        $state = $this->storage->fetch($this->id);
        $refilledAt = $state instanceof ContinuousTokenBucket ? max($now, $state->refilledAt) : $now;
        $turn = $this->roomFor($tokens, $refilledAt, $now);
        if ($maxTime !== null && $turn - $now > $maxTime) {
            throw new MaxWaitDurationExceededException(
                sprintf('The rate limiter wait time ("%.3F" seconds) is longer than the provided maximum time ("%.3F" seconds).', $turn - $now, $maxTime),
                $this->rateLimit($refilledAt, $now, $tokens, false),
            );
        }
        if ($tokens > 0) {
            $refilledAt += $tokens * $this->secondsPerToken;
            $this->storage->save(new ContinuousTokenBucket($this->id, $refilledAt));
        }

        return new Reservation($turn, $this->rateLimit($refilledAt, $now, 1, $turn <= $now));
    }

    #[\Override]
    public function consume(int $tokens = 1): RateLimit
    {
        try {
            return $this->reserve($tokens, 0)->getRateLimit();
        } catch (MaxWaitDurationExceededException $exception) {
            return $exception->getRateLimit();
        }
    }

    #[\Override]
    public function reset(): void
    {
        $this->storage->delete($this->id);
    }

    /** The first moment the bucket holds the tokens, once enough of those taken before have refilled. */
    private function roomFor(int $tokens, float $refilledAt, float $now): float
    {
        return max($now, $refilledAt - ($this->burst - $tokens) * $this->secondsPerToken);
    }

    /** The bucket as it stands now; retry-after is when it next holds the given tokens. */
    private function rateLimit(float $refilledAt, float $now, int $tokens, bool $accepted): RateLimit
    {
        // Rounded first, so a float error of a few ulps does not cost a whole token.
        $available = round($this->burst - ($refilledAt - $now) / $this->secondsPerToken, 6) |> floor(...);
        $retryAfter = '@' . sprintf('%.6F', $this->roomFor($tokens, $refilledAt, $now));

        return new RateLimit(max(0, (int) $available), new \DateTimeImmutable($retryAfter), $accepted, $this->burst);
    }
}
