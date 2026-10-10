<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health;

use App\Shared\Application\Port\AdminAlertPortInterface;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Shared\Application\Settings\SharedSettingDefinitions;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Turns health check results into one admin alert per outage.
 *
 * Each component's alert state lives in HealthAlertTable and moves through
 * HealthAlertTransition: a component that reads unhealthy owes one alert, which stays
 * owed until it is delivered, even after the component recovers; the late alert then
 * names the outage window. Only `unhealthy` alerts; `not_available` neither alerts nor
 * clears an alert. Recovery is logged, never notified.
 *
 * Delivery reads `notifications.admin_alerts` and saves the notifications inside one
 * error boundary per component: a failure is logged and the next evaluation retries.
 * While PostgreSQL itself reads unhealthy no delivery is tried, because notifications
 * are rows in it. With the setting off the owed alert is logged and dropped. Delivery
 * is at least once: a worker killed between saving the notifications and updating the
 * row repeats the alert.
 *
 * HealthMonitorSubscriber calls checkAndAlert() from a timer in HTTP worker 0.
 */
final class HealthAlertService
{

    public function __construct(
        private readonly HealthCheckService $healthCheckService,
        private readonly AdminAlertPortInterface $adminAlertPort,
        private readonly SystemSettingsPortInterface $systemSettings,
        private readonly HealthAlertTable $table,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Runs the health checks and evaluates their results. */
    public function checkAndAlert(): void
    {
        $this->evaluateAndAlert($this->healthCheckService->check());
    }

    /** @param HealthCheckResult[] $results */
    public function evaluateAndAlert(array $results): void
    {
        $now = $this->clock->now()->getTimestamp();
        $postgresqlDown = false;
        /** @var array<string, HealthAlertRow> $pending */
        $pending = [];

        foreach ($results as $result) {
            $component = $result->component;
            if ($component === HealthCheckService::POSTGRESQL && $result->status === HealthStatus::Unhealthy) {
                $postgresqlDown = true;
            }

            $before = $this->table->get($component);
            $after = HealthAlertTransition::observe($before, $result->status, $now);
            $this->logChange($component, $before, $after);
            if (!$this->save($component, $after)) {
                // Delivering without a stored row would alert again on every tick.
                continue;
            }
            if ($after->state === HealthAlertState::Pending) {
                $pending[$component] = $after;
            }
        }

        if ($pending === []) {
            return;
        }
        if ($postgresqlDown) {
            $this->logger->warning('Health alerts for {components} wait until PostgreSQL is healthy again.', [
                'components' => implode(', ', array_keys($pending)),
            ]);

            return;
        }

        foreach ($pending as $component => $row) {
            $this->save($component, HealthAlertTransition::settle($row, $this->deliver($component, $row)));
        }
    }

    private function deliver(string $component, HealthAlertRow $row): HealthAlertDelivery
    {
        try {
            if ($this->systemSettings->get(SharedSettingDefinitions::ADMIN_ALERTS) !== true) {
                $this->logger->info('Health degradation alert for {component} skipped because admin alerts are turned off.', [
                    'component' => $component,
                    'setting' => SharedSettingDefinitions::ADMIN_ALERTS,
                ]);

                return HealthAlertDelivery::Disabled;
            }

            $this->adminAlertPort->alertAdmins(
                title: "{$component} health degraded",
                body: $this->body($component, $row),
                eventType: 'admin.health_degraded',
                referenceData: ['component' => $component],
            );

            return HealthAlertDelivery::Delivered;
        } catch (\Throwable $e) {
            $this->logger->error('Health degradation alert for {component} failed; the next check retries it.', [
                'component' => $component,
                'exception' => $e,
            ]);

            return HealthAlertDelivery::Failed;
        }
    }

    private function body(string $component, HealthAlertRow $row): string
    {
        $since = $this->format($row->unhealthySince);
        if ($row->recoveredAt === null) {
            return "{$component} has been unhealthy since {$since}.";
        }

        return "{$component} was unhealthy from {$since} until {$this->format($row->recoveredAt)}. "
            . 'This alert could not be delivered during the outage.';
    }

    private function format(?int $timestamp): string
    {
        return $timestamp === null ? 'an unknown time' : gmdate('Y-m-d H:i:s', $timestamp) . ' UTC';
    }

    private function logChange(string $component, HealthAlertRow $before, HealthAlertRow $after): void
    {
        if ($before->state === HealthAlertState::Healthy && $after->state === HealthAlertState::Pending) {
            $this->logger->warning('Health degradation detected: {component} is unhealthy.', ['component' => $component]);
        } elseif ($before->state === HealthAlertState::Acknowledged && $after->state === HealthAlertState::Healthy) {
            $this->logger->info('{component} is healthy again.', ['component' => $component]);
        } elseif ($before->recoveredAt === null && $after->recoveredAt !== null) {
            $this->logger->info('{component} is healthy again; its alert is still owed and names the outage window.', ['component' => $component]);
        }
    }

    private function save(string $component, HealthAlertRow $row): bool
    {
        if ($this->table->save($component, $row)) {
            return true;
        }

        $this->logger->error('Health alert state for {component} could not be stored; its alert is not sent.', ['component' => $component]);

        return false;
    }
}
