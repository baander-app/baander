<?php

declare(strict_types=1);

namespace App\Shared\Application\DTO;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Effective configuration of one rate limiter, as compiled into the container. */
#[Exclude]
final readonly class RateLimiterDefinition
{
    public function __construct(
        public string $name,
        public string $policy,
        public int $limit,
        public ?string $interval,
        public ?string $description,
        public string $cachePool,
    ) {
    }
}
