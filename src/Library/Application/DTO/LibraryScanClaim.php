<?php

declare(strict_types=1);

namespace App\Library\Application\DTO;

use App\Library\Domain\Model\Library;
use App\Shared\Domain\Model\Uuid;

/**
 * A library claimed for a scan. The claim ID identifies the scan that holds the claim: the
 * claimer passes it in ScanLibraryCommand, so the scan renews and ends that claim and no other.
 */
final readonly class LibraryScanClaim
{
    public function __construct(
        /** The claimed library, with its `scanning` status. */
        public Library $library,
        public Uuid $claimId,
    ) {
    }
}
