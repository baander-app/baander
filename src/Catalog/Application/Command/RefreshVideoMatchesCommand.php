<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

use App\Scheduler\Domain\Model\SchedulableCommandInterface;

final readonly class RefreshVideoMatchesCommand implements SchedulableCommandInterface
{
    public static function schedulerDescription(): string
    {
        return 'Refresh matched video titles under their current provider and episode ordering.';
    }

    public static function schedulerParameters(): array
    {
        return [];
    }
}
