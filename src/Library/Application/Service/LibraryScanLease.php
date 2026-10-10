<?php

declare(strict_types=1);

namespace App\Library\Application\Service;

use App\Library\Application\Exception\LibraryScanClaimLostException;
use App\Library\Domain\Model\Library;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use Psr\Clock\ClockInterface;

/**
 * The claim a running scan holds, from LibraryScanClaims::acquire(). The scanners call renew()
 * at every file and directory; it extends the lease in the database at most once per renewal
 * interval, so frequent calls cost nothing. A renewal that finds the claim gone stops the scan.
 */
final readonly class LibraryScanLease
{
    private LibraryClaimRenewal $renewal;

    public function __construct(
        private LibraryRepositoryInterface $libraries,
        ClockInterface $clock,
        private Library $library,
        private Uuid $claimId,
        int $leaseSeconds,
        int $renewalIntervalSeconds,
    ) {
        $this->renewal = new LibraryClaimRenewal($libraries, $clock, $claimId, $leaseSeconds, $renewalIntervalSeconds, $clock->now());
    }

    public function claimId(): Uuid
    {
        return $this->claimId;
    }

    /** @throws LibraryScanClaimLostException when the claim was released or another scan or delete took it over */
    public function renew(): void
    {
        if (!$this->renewal->renew()) {
            throw LibraryScanClaimLostException::forLibrary($this->library->getName());
        }
    }

    /**
     * Ends the claim with the scan completed.
     *
     * @throws LibraryScanClaimLostException when the claim is no longer this scan's
     */
    public function complete(): void
    {
        if (!$this->libraries->endScanClaim($this->claimId, completed: true)) {
            throw LibraryScanClaimLostException::forLibrary($this->library->getName());
        }
    }

    /** Ends the claim with the scan failed; a claim another holder took over stays as it is. */
    public function fail(): void
    {
        $this->libraries->endScanClaim($this->claimId, completed: false);
    }
}
