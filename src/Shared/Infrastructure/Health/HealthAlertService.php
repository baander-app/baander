<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health;

use App\Shared\Application\Port\AdminAlertPortInterface;
use App\Shared\Application\Port\HealthAlertPortInterface;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Shared\Application\Settings\SharedSettingDefinitions;
use Psr\Log\LoggerInterface;

/**
 * Monitors health check results and fires admin alerts when component status changes.
 *
 * Tracks previous health state in memory (per-worker) and compares against
 * current state on each check call, so a component alerts once when it leaves
 * healthy and again only after it has recovered. A failed alert delivery is
 * logged and leaves the last status in place, so the next check sends the alert
 * again. The first check in a process
 * records a baseline and never alerts. The scheduled CheckHealthCommand calls
 * checkAndAlert() every five minutes in the Messenger consumer. The
 * `notifications.admin_alerts` setting turns these alerts off; the degradation
 * is still logged.
 */
final class HealthAlertService implements HealthAlertPortInterface
{
    /** @var array<string, string> Component → last known status */
    private array $previousState = [];

    public function __construct(
        private readonly HealthCheckService $healthCheckService,
        private readonly AdminAlertPortInterface $adminAlertPort,
        private readonly LoggerInterface $logger,
        private readonly SystemSettingsPortInterface $systemSettings,
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
     * Use this when the caller already ran the health checks.
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

            // Only alert on degradation (healthy → anything else); every other
            // transition, including the first check, just records the status.
            if ($previousStatus !== 'healthy' || $currentStatus === 'healthy') {
                $this->previousState[$component] = $currentStatus;
                continue;
            }

            $this->logger->warning('Health degradation detected: {component} changed from {from} to {to}', [
                'component' => $component,
                'from' => $previousStatus,
                'to' => $currentStatus,
            ]);

            if ($this->systemSettings->get(SharedSettingDefinitions::ADMIN_ALERTS) !== true) {
                $this->logger->info('Health degradation alert for {component} skipped because admin alerts are turned off.', [
                    'component' => $component,
                    'setting' => SharedSettingDefinitions::ADMIN_ALERTS,
                ]);
                $this->previousState[$component] = $currentStatus;
                continue;
            }

            // Record the new status only once the alert is out, so a failed
            // delivery (PostgreSQL down, say) is retried on the next check.
            try {
                $this->adminAlertPort->alertAdmins(
                    title: "{$component} health degraded",
                    body: "Status changed from {$previousStatus} to {$currentStatus}. Response time: " . round($result->responseTimeMs, 1) . 'ms',
                    eventType: 'admin.health_degraded',
                    referenceData: ['component' => $component],
                );
            } catch (\Throwable $e) {
                $this->logger->error('Health degradation alert for {component} failed; the next check retries it.', [
                    'component' => $component,
                    'from' => $previousStatus,
                    'to' => $currentStatus,
                    'exception' => $e,
                ]);
                continue;
            }

            $this->previousState[$component] = $currentStatus;
        }
    }
}
