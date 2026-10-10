<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health;

/**
 * The alert state machine, as pure functions. A check result moves a row first
 * (observe); a row left pending then takes the outcome of its delivery attempt (settle).
 *
 * - Healthy turns pending when its check reads unhealthy; not_available changes nothing.
 * - Pending stays pending whatever the check says, until its alert is delivered or
 *   dropped. A recovery while pending is remembered, so the late alert names the
 *   outage window; a new unhealthy reading reopens the window.
 * - Acknowledged returns to healthy only when its check reads healthy.
 */
final class HealthAlertTransition
{
    public static function observe(HealthAlertRow $row, HealthStatus $status, int $now): HealthAlertRow
    {
        return match ($row->state) {
            HealthAlertState::Healthy => $status === HealthStatus::Unhealthy
                ? new HealthAlertRow(HealthAlertState::Pending, $now)
                : $row,
            HealthAlertState::Pending => match ($status) {
                HealthStatus::Unhealthy => new HealthAlertRow(HealthAlertState::Pending, $row->unhealthySince),
                HealthStatus::Healthy => new HealthAlertRow(HealthAlertState::Pending, $row->unhealthySince, $row->recoveredAt ?? $now),
                HealthStatus::NotAvailable => $row,
            },
            HealthAlertState::Acknowledged => $status === HealthStatus::Healthy ? new HealthAlertRow() : $row,
        };
    }

    public static function settle(HealthAlertRow $row, HealthAlertDelivery $delivery): HealthAlertRow
    {
        if ($row->state !== HealthAlertState::Pending) {
            return $row;
        }

        return match ($delivery) {
            // An alert for an outage that already ended leaves nothing to acknowledge.
            HealthAlertDelivery::Delivered, HealthAlertDelivery::Disabled => $row->recoveredAt === null
                ? new HealthAlertRow(HealthAlertState::Acknowledged, $row->unhealthySince)
                : new HealthAlertRow(),
            HealthAlertDelivery::Failed => $row,
        };
    }
}
