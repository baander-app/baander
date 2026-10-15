<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\RateLimiter;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class RateLimiterSnapshot
{
    public function __construct(
        public string $name,
        public string $subject,
        public string $generation,
        public string $revision,
        public string $payload,
        public int $readAtMilliseconds,
    ) {}
}
