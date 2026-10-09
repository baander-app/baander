<?php

declare(strict_types=1);

namespace App\Library\Application\Command;

/**
 * Ends a library's scan claim on an operator's request and marks the scan failed:
 * `app:library:scan --release`, for a claim whose scan is gone. A live claim, one its scan
 * renewed within the lease, is released only with $force. The handler returns whether a claim
 * was released.
 */
final readonly class ReleaseLibraryScanClaimCommand
{
    public function __construct(
        /** The library's UUID or slug. */
        public string $library,
        /** Release a live claim too, whose scan may still be running. */
        public bool $force = false,
    ) {
    }
}
