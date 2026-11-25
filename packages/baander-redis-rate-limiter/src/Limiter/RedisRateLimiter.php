<?php

declare(strict_types=1);

namespace Baander\RedisRateLimiter\Limiter;

use Baander\RedisRateLimiter\Storage\RedisRateLimiterStore;
use Baander\RedisRateLimiter\Storage\RateLimiterStateCodec;
use Baander\RedisRateLimiter\Storage\SnapshotRateLimiterStorage;
use Baander\RedisRateLimiter\Exception\RateLimiterUnavailableException;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\Exception\MaxWaitDurationExceededException;
use Symfony\Component\RateLimiter\Policy\SlidingWindow;
use Symfony\Component\RateLimiter\Policy\Window;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Reservation;

#[Exclude]
final readonly class RedisRateLimiter implements RefundableLimiterInterface
{
    /** @param array<string, mixed> $config */
    public function __construct(private array $config, private string $subject, private RedisRateLimiterStore $store) {}

    public function consume(int $tokens = 1): RateLimit
    {
        $result = $this->evaluate('consume', $tokens, null);
        \assert($result instanceof RateLimit);

        return $result;
    }

    public function reserve(int $tokens = 1, ?float $maxTime = null): Reservation
    {
        $result = $this->evaluate('reserve', $tokens, $maxTime);
        \assert($result instanceof Reservation);

        return $result;
    }

    public function reset(): void
    {
        $this->store->reset($this->config['id'], $this->subject);
    }

    /**
     * The tokens come off the hit count of the stored window, the one the earlier attempt
     * charged unless another attempt has rolled it over since; Symfony's `add()` clamps that
     * count at zero. A window that ended more than an interval ago is gone and is not written.
     */
    public function refund(int $tokens = 1): void
    {
        if ($this->config['policy'] === 'token_bucket') {
            throw new \LogicException('A token bucket counts no hits to give back.');
        }
        $this->update($tokens, static function (SnapshotRateLimiterStorage $storage, string $id) use ($tokens): null {
            $window = $storage->fetch($id);
            if ($tokens === 0 || !($window instanceof Window || $window instanceof SlidingWindow)) {
                return null;
            }
            $window->add(-$tokens);
            if ($window->getExpirationTime() >= 1) {
                $storage->save($window);
            }

            return null;
        });
    }

    private function evaluate(string $operation, int $tokens, ?float $maxTime): RateLimit|Reservation
    {
        return $this->update($tokens, function (SnapshotRateLimiterStorage $storage, string $id) use ($operation, $tokens, $maxTime): RateLimit|Reservation {
            $policy = $this->policy($storage, $id);

            return $operation === 'consume' ? $policy->consume($tokens) : $policy->reserve($tokens, $maxTime);
        });
    }

    /**
     * Runs $step on a private copy of the subject's state and commits what it saved, reading
     * again when another writer committed first. Nothing is written when $step saves nothing.
     *
     * @template T
     *
     * @param callable(SnapshotRateLimiterStorage, string): T $step
     *
     * @return T
     */
    private function update(int $tokens, callable $step): mixed
    {
        if ($tokens < 0 || $tokens > $this->config['limit']) {
            throw new \InvalidArgumentException('Token count must be between zero and the limiter capacity.');
        }
        $started = hrtime(true);
        $id = $this->config['id'] . '-' . $this->subject;
        $codec = new RateLimiterStateCodec($this->config, $id);
        for ($attempt = 0; $attempt < 8 && hrtime(true) - $started < 2_000_000_000; ++$attempt) {
            $snapshot = $this->store->read($this->config['id'], $this->subject);
            $this->checkDeadline($started);
            $storage = new SnapshotRateLimiterStorage($id, $codec->decode($snapshot->payload));
            try {
                $result = $step($storage, $id);
            } catch (MaxWaitDurationExceededException $exception) {
                $this->checkDeadline($started);
                throw $exception;
            }
            $this->checkDeadline($started);
            $saved = $storage->saved();
            if ($saved === null) {
                return $result;
            }
            if ($this->store->commit($snapshot, $codec->encode($saved), $saved->getExpirationTime() ?? 0)) {
                return $result;
            }
        }

        throw new RateLimiterUnavailableException();
    }

    private function policy(SnapshotRateLimiterStorage $storage, string $id): LimiterInterface
    {
        return $this->config['policy'] === 'token_bucket'
            ? ContinuousTokenBucketLimiter::fromConfig($this->config, $id, $storage)
            : new RateLimiterFactory($this->config, $storage)->create($this->subject);
    }

    private function checkDeadline(int $started): void
    {
        if (hrtime(true) - $started >= 2_000_000_000) {
            throw new RateLimiterUnavailableException();
        }
    }
}
