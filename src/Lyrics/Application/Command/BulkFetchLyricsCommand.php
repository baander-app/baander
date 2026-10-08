<?php

declare(strict_types=1);

namespace App\Lyrics\Application\Command;

use App\Scheduler\Domain\Model\SchedulableCommandInterface;
use App\Scheduler\Domain\Model\SchedulerParameterSchema;

/**
 * Command to bulk-fetch lyrics for songs that don't have them yet.
 */
final readonly class BulkFetchLyricsCommand implements SchedulableCommandInterface
{
    use SchedulerParameterSchema;

    /** Pause between lyrics fetches, which keeps LRCLIB from throttling us. */
    public const int DEFAULT_DELAY_MS = 500;

    public function __construct(
        private ?int $limit = null,
        private ?int $delayMs = self::DEFAULT_DELAY_MS,
    ) {
    }

    public function getLimit(): ?int
    {
        return $this->limit;
    }

    public function getDelayMs(): ?int
    {
        return $this->delayMs;
    }

    public static function schedulerDescription(): string
    {
        return 'Bulk-fetch lyrics for songs that do not have them yet.';
    }

    public static function schedulerParameters(): array
    {
        return self::buildSchemaFromOaClass(BulkFetchLyricsSchedulerParams::class);
    }
}
