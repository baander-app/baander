<?php

declare(strict_types=1);

namespace App\Library\Application\Command;

/**
 * Ends a library's claim on an operator's request: `app:library:scan --release`, for a claim
 * whose scan or delete with files is gone. A scan claim marks its scan failed; a delete claim
 * leaves the scan status alone. A live claim, one its holder renewed within the lease, is
 * released only with $force. The handler returns the kind of the claim it released, or null.
 */
final readonly class ReleaseLibraryScanClaimCommand
{
    public function __construct(
        /** The library's UUID or slug. */
        public string $library,
        /** Release a live claim too, whose holder may still be running. */
        public bool $force = false,
    ) {
    }
}
