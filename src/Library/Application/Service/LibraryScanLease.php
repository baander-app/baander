<?php

declare(strict_types=1);

namespace App\Library\Application\Service;

use App\Library\Application\Exception\LibraryScanClaimLostException;
use App\Library\Domain\Model\Library;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * The claim a running scan holds, from LibraryScanClaims::acquire(). The scanners call renew()
 * at every file and directory; it extends the lease in the database at most once per renewal
 * interval, so frequent calls cost nothing. A renewal that finds the claim gone stops the scan.
 */
final class LibraryScanLease
{
    private DateTimeImmutable $renewedAt;

    public function __construct(
        private readonly LibraryRepositoryInterface $libraries,
        private readonly ClockInterface $clock,
        private readonly Library $library,
        private readonly Uuid $claimId,
        private readonly int $leaseSeconds,
        private readonly int $renewalIntervalSeconds,
    ) {
        $this->renewedAt = $clock->now();
    }

    public function claimId(): Uuid
    {
        return $this->claimId;
    }

    /** @throws LibraryScanClaimLostException when the claim was released or another scan took it over */
    public function renew(): void
    {
        $now = $this->clock->now();
        $elapsed = $now->getTimestamp() - $this->renewedAt->getTimestamp();
        // A wall clock set back counts as due, so a clock change cannot starve the lease.
        if ($elapsed >= 0 && $elapsed < $this->renewalIntervalSeconds) {
            return;
        }

        if (!$this->libraries->renewScanClaim($this->claimId, $this->leaseSeconds)) {
            throw LibraryScanClaimLostException::forLibrary($this->library->getName());
        }
        $this->renewedAt = $now;
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

    /** Ends the claim with the scan failed; a claim another scan holds now stays as it is. */
    public function fail(): void
    {
        $this->libraries->endScanClaim($this->claimId, completed: false);
    }
}
