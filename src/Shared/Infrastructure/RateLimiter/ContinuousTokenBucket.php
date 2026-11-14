<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\RateLimiter;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\RateLimiter\LimiterStateInterface;

/**
 * The stored state of a continuously refilling token bucket: the moment, in seconds since the
 * epoch, at which every token taken so far has refilled and the bucket is full again.
 */
#[Exclude]
final readonly class ContinuousTokenBucket implements LimiterStateInterface
{
    public function __construct(private string $id, public float $refilledAt)
    {
    }

    #[\Override]
    public function getId(): string
    {
        return $this->id;
    }

    /** A bucket that is gone counts as full, so it only has to outlive its refill. */
    #[\Override]
    public function getExpirationTime(): int
    {
        return max(1, (int) ceil($this->refilledAt - microtime(true)));
    }

    /** @return array{id: string, refilledAt: float} */
    public function __serialize(): array
    {
        return ['id' => $this->id, 'refilledAt' => $this->refilledAt];
    }

    /** @param array{id: string, refilledAt: float} $data */
    public function __unserialize(array $data): void
    {
        ['id' => $this->id, 'refilledAt' => $this->refilledAt] = $data;
    }
}
