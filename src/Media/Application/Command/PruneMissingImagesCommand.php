<?php

declare(strict_types=1);

namespace App\Media\Application\Command;

use App\Scheduler\Domain\Model\SchedulableCommandInterface;

final readonly class PruneMissingImagesCommand implements SchedulableCommandInterface
{
    public function __construct()
    {
    }

    public static function schedulerDescription(): string
    {
        return 'Prune media images where the source file no longer exists on disk.';
    }

    public static function schedulerParameters(): array
    {
        return [];
    }
}
