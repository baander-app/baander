<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

/**
 * Checks system health and alerts administrators when a component degrades.
 */
interface HealthAlertPortInterface
{
    /**
     * Alerts only on a change from healthy to another status, and only while the
     * `notifications.admin_alerts` setting is on; a degradation is always logged.
     */
    public function checkAndAlert(): void;
}
