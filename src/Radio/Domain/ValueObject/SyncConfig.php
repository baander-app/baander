<?php

declare(strict_types=1);

namespace App\Radio\Domain\ValueObject;

final readonly class SyncConfig
{
    /**
     * @param string $syncUrl URL to the source API
     * @param string|null $schedule Cron expression for scheduled sync
     * @param array<string, mixed> $config Additional source-specific configuration
     */
    public function __construct(
        public string $syncUrl,
        public ?string $schedule,
        public array $config = [],
    ) {
    }
}
