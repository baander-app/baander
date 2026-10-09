<?php

declare(strict_types=1);

namespace App\Library\Application\Service;

use App\Library\Application\DTO\LibraryScanClaim;
use App\Library\Application\DTO\LibraryScanClaimResult;
use App\Library\Application\Exception\LibraryNotFoundException;
use App\Library\Application\Exception\LibraryScanAlreadyRunningException;
use App\Library\Application\Exception\LibraryScanClaimLiveException;
use App\Library\Domain\Model\Library;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Library\Domain\ValueObject\ScanClaimRelease;
use App\Shared\Domain\Model\Uuid;
use Psr\Clock\ClockInterface;

/**
 * A scan claims its library first, so one scan runs per library. The claim is a lease that its
 * scan renews while it works: a claim whose scan was lost before it started (a queued task
 * dropped by a server restart) or that died (a killed process) lapses, and the next claim takes
 * the library over. Each claim has an ID that the scan carries, so a scan renews and ends only
 * its own claim, and a scan that lost its claim stops. The web, the console and the scan job all
 * claim through this service.
 */
final readonly class LibraryScanClaims
{
    /**
     * How long a claim stays valid without renewal. Long enough for the longest pause between
     * renewals: walking the directory tree of a large library, or hashing one large video file,
     * which can take minutes on network storage. A queued scan's claim counts from the moment it
     * was queued; if it lapses while the scan waits for a task worker, the library reads as
     * failed, and the scan renews its claim when it starts, unless another scan claimed the
     * library meanwhile, in which case it stops with a conflict.
     */
    public const int DEFAULT_LEASE_SECONDS = 900;

    /** A running scan renews its claim at most this often. */
    public const int RENEWAL_INTERVAL_SECONDS = 60;

    public function __construct(
        private LibraryLookup $lookup,
        private LibraryRepositoryInterface $libraries,
        private ClockInterface $clock,
        private int $leaseSeconds = self::DEFAULT_LEASE_SECONDS,
    ) {
    }

    /**
     * Claims the library for a new scan.
     *
     * @throws LibraryNotFoundException
     * @throws LibraryScanAlreadyRunningException
     */
    public function claim(string $identifier): LibraryScanClaim
    {
        $library = $this->lookup->byIdentifier($identifier);

        return $this->claimLibrary($library)
            ?? throw LibraryScanAlreadyRunningException::forLibrary($library->getName());
    }

    /** Claims every library that no live claim holds; the others are skipped. */
    public function claimAll(): LibraryScanClaimResult
    {
        $claimed = [];
        $skipped = [];
        foreach ($this->libraries->findAllOrdered() as $library) {
            $claim = $this->claimLibrary($library);
            if ($claim === null) {
                $skipped[] = $library;
            } else {
                $claimed[] = $claim;
            }
        }

        return new LibraryScanClaimResult($claimed, $skipped);
    }

    /**
     * Takes the claim $claimId for the scan that starts now: renews it when it is still the
     * library's, or claims the library when no live claim holds it, as for a retried scan whose
     * claim ended.
     *
     * @throws LibraryScanAlreadyRunningException when another scan holds a live claim
     */
    public function acquire(Library $library, Uuid $claimId): LibraryScanLease
    {
        if (!$this->libraries->claimScan($library->getId(), $claimId, $this->leaseSeconds)) {
            throw LibraryScanAlreadyRunningException::forLibrary($library->getName());
        }

        return new LibraryScanLease($this->libraries, $this->clock, $library, $claimId, $this->leaseSeconds, self::RENEWAL_INTERVAL_SECONDS);
    }

    /**
     * Ends the claim $claimId, whose scan will not run to its end, and marks that scan failed.
     *
     * @return bool false when the claim had already ended or another scan took it over
     */
    public function end(Uuid $claimId): bool
    {
        return $this->libraries->endScanClaim($claimId, completed: false);
    }

    /**
     * Releases the library's claim for an operator and marks its scan failed. A live claim is
     * released only with $force, since its scan may still be running.
     *
     * @return bool whether a claim was released; false when none held the library
     *
     * @throws LibraryNotFoundException
     * @throws LibraryScanClaimLiveException when the claim is live and $force is false
     */
    public function release(string $identifier, bool $force): bool
    {
        $library = $this->lookup->byIdentifier($identifier);

        return match ($this->libraries->releaseScanClaim($library->getId(), $force)) {
            ScanClaimRelease::Released => true,
            ScanClaimRelease::NoClaim => false,
            ScanClaimRelease::Live => throw LibraryScanClaimLiveException::forLibrary($library->getName()),
        };
    }

    private function claimLibrary(Library $library): ?LibraryScanClaim
    {
        $claimId = new Uuid();
        if (!$this->libraries->claimScan($library->getId(), $claimId, $this->leaseSeconds)) {
            return null;
        }

        $claimed = $this->libraries->findByUuid($library->getId())
            ?? throw LibraryNotFoundException::forIdentifier($library->getId()->toString());

        return new LibraryScanClaim($claimed, $claimId);
    }
}
