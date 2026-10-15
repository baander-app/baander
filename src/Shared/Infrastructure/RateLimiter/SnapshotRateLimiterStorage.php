<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\RateLimiter;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\RateLimiter\LimiterStateInterface;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

/** One policy evaluation gets its own mutable copy; unsaved changes never reach Redis. */
#[Exclude]
final class SnapshotRateLimiterStorage implements StorageInterface
{
    private ?LimiterStateInterface $saved = null;

    public function __construct(private readonly string $id, private readonly ?LimiterStateInterface $state) {}

    public function fetch(string $limiterStateId): ?LimiterStateInterface
    {
        $this->assertId($limiterStateId);

        return $this->state;
    }

    public function save(LimiterStateInterface $limiterState): void
    {
        $this->assertId($limiterState->getId());
        $this->saved = $limiterState;
    }

    public function delete(string $limiterStateId): void
    {
        throw new \LogicException('Subject reset must use the atomic Redis reset operation.');
    }

    public function saved(): ?LimiterStateInterface
    {
        return $this->saved;
    }

    private function assertId(string $id): void
    {
        if ($id !== $this->id) {
            throw new \LogicException('Policy accessed an unexpected limiter state.');
        }
    }
}
