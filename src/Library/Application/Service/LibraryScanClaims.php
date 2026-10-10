<?php

declare(strict_types=1);

namespace App\Library\Application\Service;

use App\Library\Application\DTO\LibraryScanClaim;
use App\Library\Application\DTO\LibraryScanClaimResult;
use App\Library\Application\Exception\LibraryBusyException;
use App\Library\Application\Exception\LibraryNotFoundException;
use App\Library\Application\Exception\LibraryScanClaimLiveException;
use App\Library\Domain\Model\Library;
use App\Library\Domain\Repository\LibraryRepositoryInterface;
use App\Library\Domain\ValueObject\LibraryClaimAttempt;
use App\Library\Domain\ValueObject\LibraryClaimKind;
use App\Shared\Domain\Model\Uuid;
use Psr\Clock\ClockInterface;

/**
 * A scan claims its library first, so one scan runs per library, and none while a delete with
 * files holds the library (LibraryMediaFilesInterface::claim() takes that claim, of the kind
 * `delete`). The claim is a lease that its holder renews while it works: a claim whose scan was
 * lost before it started (a queued task dropped by a server restart) or that died (a killed
 * process) lapses, and the next claim takes the library over. Each claim has an ID that the scan
 * carries, so a scan renews and ends only its own claim, and a scan that lost its claim stops.
 * The web, the console and the scan job all claim through this service.
 */
final readonly class LibraryScanClaims
{
    /**
     * How long a claim stays valid without renewal. Long enough for the longest pause between
     * renewals: walking the directory tree of a large library, or hashing one large video file,
     * which can take minutes on network storage. A queued scan's claim counts from the moment it
     * was queued; if it lapses while the scan waits for a task worker, the library reads as
     * failed, and the scan renews its claim when it starts, unless another claim took the
     * library meanwhile, in which case it stops with a conflict. A delete with files holds its
     * claim for the same lease.
     */
    public const int DEFAULT_LEASE_SECONDS = 900;

    /** A running scan or delete renews its claim at most this often. */
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
     * @throws LibraryBusyException     when another scan or a delete with files holds a live claim
     */
    public function claim(string $identifier): LibraryScanClaim
    {
        $library = $this->lookup->byIdentifier($identifier);
        $claimId = new Uuid();
        self::claimed($library, $this->libraries->claimScan($library->getId(), $claimId, $this->leaseSeconds));

        return $this->claimOf($library, $claimId);
    }

    /** Claims every library that no live claim holds; the others are skipped. */
    public function claimAll(): LibraryScanClaimResult
    {
        $claimed = [];
        $skipped = [];
        foreach ($this->libraries->findAllOrdered() as $library) {
            $claimId = new Uuid();
            if ($this->libraries->claimScan($library->getId(), $claimId, $this->leaseSeconds)->claimed) {
                $claimed[] = $this->claimOf($library, $claimId);
            } else {
                $skipped[] = $library;
            }
        }

        return new LibraryScanClaimResult($claimed, $skipped);
    }

    /**
     * Takes the claim $claimId for the scan that starts now: renews it when it is still the
     * library's, or claims the library when no live claim holds it, as for a retried scan whose
     * claim ended.
     *
     * @throws LibraryBusyException when another scan or a delete with files holds a live claim
     */
    public function acquire(Library $library, Uuid $claimId): LibraryScanLease
    {
        self::claimed($library, $this->libraries->claimScan($library->getId(), $claimId, $this->leaseSeconds));

        return new LibraryScanLease($this->libraries, $this->clock, $library, $claimId, $this->leaseSeconds, self::RENEWAL_INTERVAL_SECONDS);
    }

    /**
     * Ends the claim $claimId, whose scan will not run to its end, and marks that scan failed.
     *
     * @return bool false when the claim had already ended or another holder took it over
     */
    public function end(Uuid $claimId): bool
    {
        return $this->libraries->endScanClaim($claimId, completed: false);
    }

    /**
     * Releases the library's claim for an operator: a scan claim marks its scan failed, and a
     * delete claim leaves the scan status alone. A live claim is released only with $force,
     * since its holder may still be running.
     *
     * @return LibraryClaimKind|null the kind of the claim released; null when none held the library
     *
     * @throws LibraryNotFoundException
     * @throws LibraryScanClaimLiveException when the claim is live and $force is false
     */
    public function release(string $identifier, bool $force): ?LibraryClaimKind
    {
        $library = $this->lookup->byIdentifier($identifier);
        $release = $this->libraries->releaseClaim($library->getId(), $force);
        if ($release->live !== null) {
            throw LibraryScanClaimLiveException::heldBy($library->getName(), $release->live);
        }

        return $release->released;
    }

    /**
     * @throws LibraryBusyException     when a live claim holds the library
     * @throws LibraryNotFoundException when the library is gone
     */
    private static function claimed(Library $library, LibraryClaimAttempt $attempt): void
    {
        if ($attempt->claimed) {
            return;
        }

        throw $attempt->holder === null
            ? LibraryNotFoundException::forIdentifier($library->getId()->toString())
            : LibraryBusyException::heldBy($library->getName(), $attempt->holder);
    }

    private function claimOf(Library $library, Uuid $claimId): LibraryScanClaim
    {
        $claimed = $this->libraries->findByUuid($library->getId())
            ?? throw LibraryNotFoundException::forIdentifier($library->getId()->toString());

        return new LibraryScanClaim($claimed, $claimId);
    }
}
