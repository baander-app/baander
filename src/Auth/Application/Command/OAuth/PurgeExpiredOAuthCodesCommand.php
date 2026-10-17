<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\OAuth;

use App\Scheduler\Domain\Model\SchedulableCommandInterface;

/**
 * Deletes authorization codes and device codes that expired more than an hour ago.
 *
 * The initial schema schedules it daily; `app:oauth:purge-codes`
 * dispatches the same command on demand.
 */
final readonly class PurgeExpiredOAuthCodesCommand implements SchedulableCommandInterface
{
    public static function schedulerDescription(): string
    {
        return 'Delete OAuth authorization codes and device codes that expired more than an hour ago.';
    }

    public static function schedulerParameters(): array
    {
        return [];
    }
}
