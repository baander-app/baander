<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\RateLimiter;

use App\Shared\Application\Exception\RateLimiterUnavailableException;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\Exception\MaxWaitDurationExceededException;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Reservation;

#[Exclude]
final readonly class RedisRateLimiter implements LimiterInterface
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

    private function evaluate(string $operation, int $tokens, ?float $maxTime): RateLimit|Reservation
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
            $policy = (new RateLimiterFactory($this->config, $storage))->create($this->subject);
            try {
                $result = $operation === 'consume' ? $policy->consume($tokens) : $policy->reserve($tokens, $maxTime);
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

    private function checkDeadline(int $started): void
    {
        if (hrtime(true) - $started >= 2_000_000_000) {
            throw new RateLimiterUnavailableException();
        }
    }
}
