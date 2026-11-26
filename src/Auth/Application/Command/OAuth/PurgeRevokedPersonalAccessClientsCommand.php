<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\OAuth;

use App\Scheduler\Domain\Model\SchedulableCommandInterface;

/**
 * Deletes revoked personal access clients, together with every token issued to them.
 *
 * A migration schedules it daily; `app:oauth:client:purge-revoked` dispatches the same
 * command on demand.
 */
final readonly class PurgeRevokedPersonalAccessClientsCommand implements SchedulableCommandInterface
{
    public static function schedulerDescription(): string
    {
        return 'Delete revoked personal access clients and the tokens issued to them.';
    }

    public static function schedulerParameters(): array
    {
        return [];
    }
}
