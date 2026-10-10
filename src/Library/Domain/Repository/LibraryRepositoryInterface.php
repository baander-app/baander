<?php

declare(strict_types=1);

namespace App\Library\Domain\Repository;

use App\Library\Domain\Model\Library;
use App\Library\Domain\ValueObject\LibraryClaimAttempt;
use App\Library\Domain\ValueObject\LibraryClaimKind;
use App\Library\Domain\ValueObject\LibraryClaimRelease;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;

interface LibraryRepositoryInterface
{
    /** @return Library[] */
    public function findVisible(LibraryReadScope $scope, ?LibraryType $type = null): array;

    public function findVisibleByUuid(Uuid $uuid, LibraryReadScope $scope): ?Library;

    public function findVisibleBySlug(LibrarySlug $slug, LibraryReadScope $scope): ?Library;

    public function save(Library $library): void;

    public function findByUuid(Uuid $uuid): ?Library;

    public function findBySlug(LibrarySlug $slug): ?Library;

    /**
     * @return Library[]
     */
    public function findByType(LibraryType $type): array;

    /**
     * @return Library[]
     */
    public function findAllOrdered(): array;

    /**
     * @return Library[]
     */
    public function findAccessibleByUser(Uuid $userId): array;

    public function delete(Library $library): void;

    /**
     * Claims the library for the scan $claimId until $leaseSeconds from now, by the database
     * clock. One statement that locks the library row: it succeeds while no other live claim
     * holds the library, that is when the library holds no claim, its claim has lapsed, or the
     * claim is already $claimId's, so of two concurrent claims exactly one wins. A scan claim
     * sets the discovery status to `scanning`; ending or releasing it sets the outcome.
     */
    public function claimScan(Uuid $libraryId, Uuid $claimId, int $leaseSeconds): LibraryClaimAttempt;

    /**
     * Claims the library for the delete with files $claimId, as claimScan() claims it for a
     * scan, but leaves the discovery status and the last scan time alone. A lapsed scan claim it
     * takes over belonged to a scan that died, which it marks failed. It first waits for every
     * transaction that read the claim through liveClaimKindForImport() to end.
     */
    public function claimDelete(Uuid $libraryId, Uuid $claimId, int $leaseSeconds): LibraryClaimAttempt;

    /**
     * Extends the lease of the claim $claimId, of either kind, to $leaseSeconds from now, even if
     * it has lapsed, as long as no other holder took the claim over.
     *
     * @return bool false when the claim was released or another holder took it over
     */
    public function renewClaim(Uuid $claimId, int $leaseSeconds): bool;

    /**
     * Ends the claim of the scan $claimId with its outcome: `completed`, which records the scan
     * time, or `failed`. Does nothing when the claim is no longer that scan's.
     *
     * @return bool whether the scan still held its claim
     */
    public function endScanClaim(Uuid $claimId, bool $completed): bool;

    /**
     * Ends the claim of the delete with files $claimId and leaves the discovery status alone.
     * Does nothing when the claim is no longer that delete's.
     *
     * @return bool whether the delete still held its claim
     */
    public function endDeleteClaim(Uuid $claimId): bool;

    /**
     * Ends whichever claim holds the library, for a holder that will not finish; a scan claim
     * marks its scan failed. A live claim is released only when $evenIfLive is true.
     */
    public function releaseClaim(Uuid $libraryId, bool $evenIfLive): LibraryClaimRelease;

    /** The kind of the claim on the library that has not lapsed, by the database clock; null when none. */
    public function liveClaimKind(Uuid $libraryId): ?LibraryClaimKind;

    /**
     * Reads the live claim as liveClaimKind() does, inside the caller's transaction, which must be
     * open, and keeps claimDelete() of the library waiting until that transaction ends. A write
     * in the same transaction that runs only when this is not a delete claim therefore commits
     * before a delete with files claims the library, or sees its claim.
     */
    public function liveClaimKindForImport(Uuid $libraryId): ?LibraryClaimKind;
}
