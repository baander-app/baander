<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

use App\Scheduler\Domain\Model\SchedulableCommandInterface;

final readonly class BatchExtractCoversCommand implements SchedulableCommandInterface
{
    public static function schedulerDescription(): string
    {
        return 'Extract cover artwork from audio files that are missing album art.';
    }

    public static function schedulerParameters(): array
    {
        return [];
    }
}
