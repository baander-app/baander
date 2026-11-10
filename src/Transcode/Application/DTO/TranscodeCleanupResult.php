<?php

declare(strict_types=1);

namespace App\Transcode\Application\DTO;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** What one run of the transcode job cleanup did. */
#[Exclude]
final readonly class TranscodeCleanupResult
{
    public function __construct(
        /** Stale sessions that expired in this run. */
        public int $expired,
        /** Orphaned jobs removed with their output. */
        public int $cleaned,
    ) {}

    /** @return array{expired: int, cleaned: int} The data of the cleanup endpoint and of the command's JSON output. */
    public function toArray(): array
    {
        return ['expired' => $this->expired, 'cleaned' => $this->cleaned];
    }
}
