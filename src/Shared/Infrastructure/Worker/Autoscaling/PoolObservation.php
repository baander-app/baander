<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Worker\Autoscaling;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class PoolObservation
{
    public function __construct(
        public float $sampledAt,
        public int $occupied,
        public int $busy,
        public int $parked,
        public int $knownSlotCount,
        public ?float $completedOccupancySeconds = null,
        public ?float $maxInFlightSeconds = null,
    ) {
    }
}
