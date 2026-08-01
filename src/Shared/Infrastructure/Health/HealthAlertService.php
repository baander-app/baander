<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health;

use App\Shared\Application\Port\AdminAlertPortInterface;
use Psr\Log\LoggerInterface;

/**
 * Monitors health check results and fires admin alerts when component status changes.
 *
 * Tracks previous health state in memory (per-worker) and compares against
 * current state on each check call.
 */
final class HealthAlertService
{
    /** @var array<string, string> Component → last known status */
    private array $previousState = [];

    public function __construct(
        private readonly HealthCheckService $healthCheckService,
        private readonly AdminAlertPortInterface $adminAlertPort,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Check current health and alert admins if any component degraded.
     * Fetches health results internally.
     */
    public function checkAndAlert(): void
    {
        $this->evaluate($this->healthCheckService->check());
    }

    /**
     * Evaluate pre-fetched health results and alert on degradation.
     * Use this when health results are already available (e.g., from SSE polling).
     *
     * @param HealthCheckResult[] $results
     */
    public function evaluateAndAlert(array $results): void
    {
        $this->evaluate($results);
    }

    /**
     * @param HealthCheckResult[] $results
     */
    private function evaluate(array $results): void
    {

        foreach ($results as $result) {
            $component = $result->component;
            $currentStatus = $result->status->value;

            $previousStatus = $this->previousState[$component] ?? null;

            // Store current state
            $this->previousState[$component] = $currentStatus;

            // Skip if no previous state (first run) or status unchanged
            if ($previousStatus === null || $previousStatus === $currentStatus) {
                continue;
            }

            // Only alert on degradation (healthy → anything else)
            if ($previousStatus === 'healthy' && $currentStatus !== 'healthy') {
                $this->logger->warning('Health degradation detected: {component} changed from {from} to {to}', [
                    'component' => $component,
                    'from' => $previousStatus,
                    'to' => $currentStatus,
                ]);

                $this->adminAlertPort->alertAdmins(
                    title: "{$component} health degraded",
                    body: "Status changed from {$previousStatus} to {$currentStatus}. Response time: " . round($result->responseTimeMs, 1) . 'ms',
                    eventType: 'admin.health_degraded',
                    referenceData: ['component' => $component],
                );
            }
        }
    }
}
