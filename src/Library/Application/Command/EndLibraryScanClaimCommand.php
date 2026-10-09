<?php

declare(strict_types=1);

namespace App\Library\Application\Command;

/**
 * Ends a scan claim whose holder cannot run its scan to the end, and marks the scan failed: the
 * console scan when its scan fails or is interrupted. A claim another scan took over stays. The
 * handler returns whether the claim was still the holder's.
 */
final readonly class EndLibraryScanClaimCommand
{
    public function __construct(
        /** The claim ID from LibraryScanClaim. */
        public string $claimId,
    ) {
    }
}
