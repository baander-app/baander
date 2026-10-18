<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker\Autoscaling;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class ScalingDecision implements \JsonSerializable
{
    /** @param array<string, int|float|string|null> $evidence */
    public function __construct(
        public int $target,
        public string $reason,
        public string $confidence,
        public float $cooldownRemainingSeconds,
        public array $evidence,
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
