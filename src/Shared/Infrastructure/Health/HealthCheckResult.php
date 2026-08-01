<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health;

final readonly class HealthCheckResult
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public string $component,
        public HealthStatus $status,
        public float $responseTimeMs,
        public array $details = [],
    ) {
    }

    public function isHealthy(): bool
    {
        return $this->status === HealthStatus::Healthy;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'component'      => $this->component,
            'status'         => $this->status->value,
            'responseTimeMs' => round($this->responseTimeMs, 2),
            'details'        => $this->details,
        ];
    }
}
