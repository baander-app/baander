<?php

declare(strict_types=1);

namespace App\Library\Application\Command;

/**
 * Ends a library's scan claim and marks the scan failed: `app:library:scan --release` for a
 * claim a killed process left behind, and the console scan when it fails or is interrupted.
 * The handler returns whether a claim was released.
 */
final readonly class ReleaseLibraryScanClaimCommand
{
    public function __construct(
        /** The library's UUID or slug. */
        public string $library,
    ) {
    }
}
