<?php

declare(strict_types=1);

namespace App\Shared\Application\Command;

use App\Scheduler\Domain\Model\SchedulableCommandInterface;

/**
 * Checks system health and alerts administrators when a component degrades.
 *
 * Migration Version20261007140000 schedules it every five minutes. Whether a
 * degradation alerts administrators follows the `notifications.admin_alerts` setting.
 */
final readonly class CheckHealthCommand implements SchedulableCommandInterface
{
    public static function schedulerDescription(): string
    {
        return 'Check system health and alert administrators when a component degrades.';
    }

    public static function schedulerParameters(): array
    {
        return [];
    }
}
