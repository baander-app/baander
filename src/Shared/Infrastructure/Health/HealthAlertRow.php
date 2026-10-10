<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health;

/**
 * One component's alert state. unhealthySince and recoveredAt are Unix timestamps:
 * when the outage the row tracks began, and when its component first read healthy
 * again while the alert was still owed.
 */
final readonly class HealthAlertRow
{
    public function __construct(
        public HealthAlertState $state = HealthAlertState::Healthy,
        public ?int $unhealthySince = null,
        public ?int $recoveredAt = null,
    ) {
    }
}
