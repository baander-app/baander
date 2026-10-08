<?php

declare(strict_types=1);

namespace App\Library\Application\Command;

/**
 * Claims a library for a scan and queues the scan: the admin panel's POST /api/libraries/{id}/scan.
 * `app:library:scan` claims with ClaimLibraryScanCommand and runs the scan in its own process.
 */
final readonly class StartLibraryScanCommand
{
    public function __construct(
        /** The library's UUID or slug. */
        public string $library,
        /** Re-read files the index already knows. */
        public bool $rescan = false,
    ) {
    }
}
