<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

use App\Scheduler\Domain\Model\SchedulableCommandInterface;

final readonly class RecoverVideoMatchQueueCommand implements SchedulableCommandInterface
{
    public static function schedulerDescription(): string
    {
        return 'Resume due video matching and titles awaiting an enabled, configured provider.';
    }

    public static function schedulerParameters(): array
    {
        return [];
    }
}
