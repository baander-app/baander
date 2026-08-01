<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

interface AdminAlertPortInterface
{
    /**
     * Send an alert notification to all admin users.
     *
     * @param string $title Notification title
     * @param string $body Notification body
     * @param string $eventType Event type identifier (e.g., 'admin.job_failed')
     * @param array<string, mixed>|null $referenceData Optional reference data for navigation
     */
    public function alertAdmins(string $title, string $body, string $eventType, ?array $referenceData = null): void;
}
