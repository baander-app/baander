<?php

declare(strict_types=1);

namespace App\Library\Domain\Repository;

use App\Library\Domain\Model\Library;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Library\Domain\ValueObject\ScanClaimRelease;
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
     * clock. One conditional update: it succeeds while no other scan holds a live claim, that
     * is when the library holds no claim, its claim has lapsed, or the claim is already
     * $claimId's, so of two concurrent claims exactly one wins. A claim sets the discovery
     * status to `scanning`; ending or releasing it sets the outcome.
     *
     * @return bool false when another scan holds a live claim or the library does not exist
     */
    public function claimScan(Uuid $libraryId, Uuid $claimId, int $leaseSeconds): bool;

    /**
     * Extends the lease of the scan $claimId to $leaseSeconds from now, even if it has lapsed,
     * as long as no other scan took the claim over.
     *
     * @return bool false when the claim was released or another scan took it over
     */
    public function renewScanClaim(Uuid $claimId, int $leaseSeconds): bool;

    /**
     * Ends the claim of the scan $claimId with its outcome: `completed`, which records the scan
     * time, or `failed`. Does nothing when the claim is no longer that scan's.
     *
     * @return bool whether the scan still held its claim
     */
    public function endScanClaim(Uuid $claimId, bool $completed): bool;

    /**
     * Ends whichever claim holds the library and marks its scan failed, for a claim whose scan
     * will not finish. A live claim is released only when $evenIfLive is true.
     */
    public function releaseScanClaim(Uuid $libraryId, bool $evenIfLive): ScanClaimRelease;
}
