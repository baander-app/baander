<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Repository;

use App\Catalog\Domain\Model\VideoMatchState;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

interface VideoMatchStateRepositoryInterface
{
    public function findBySeries(Uuid $seriesId): ?VideoMatchState;

    public function findByMovie(Uuid $movieId): ?VideoMatchState;

    public function requestSeries(Uuid $seriesId, string $source, DateTimeImmutable $dueAt): bool;

    public function requestMovie(Uuid $movieId, string $source, DateTimeImmutable $dueAt): bool;

    public function markAwaitingSeries(Uuid $seriesId, string $source, DateTimeImmutable $dueAt): bool;

    public function markAwaitingMovie(Uuid $movieId, string $source, DateTimeImmutable $dueAt): bool;

    public function recordAdminSeriesMatched(Uuid $seriesId, DateTimeImmutable $checkedAt): void;

    public function recordAdminMovieMatched(Uuid $movieId, DateTimeImmutable $checkedAt): void;

    public function cancelSeriesRematch(Uuid $seriesId, DateTimeImmutable $checkedAt, bool $matched = true): ?VideoMatchState;

    public function cancelMovieRematch(Uuid $movieId, DateTimeImmutable $checkedAt, bool $matched = true): ?VideoMatchState;

    /** @param int<1, max> $leaseSeconds */
    public function claimOldestDueRequest(DateTimeImmutable $now, Uuid $claimId, int $leaseSeconds): ?VideoMatchState;

    /** Locks the live claim inside the transaction that applies its provider answer. */
    public function lockClaimForApply(Uuid $stateId, Uuid $claimId): ?VideoMatchState;

    public function completeClaim(Uuid $stateId, Uuid $claimId, DateTimeImmutable $checkedAt): ?VideoMatchState;

    public function markClaimedForReview(Uuid $stateId, Uuid $claimId, string $reason, ?int $bestScore, DateTimeImmutable $checkedAt): ?VideoMatchState;

    public function markClaimedAwaitingProvider(Uuid $stateId, Uuid $claimId, DateTimeImmutable $checkedAt): ?VideoMatchState;

    public function postponeClaimedRequest(Uuid $stateId, Uuid $claimId, DateTimeImmutable $dueAt, ?string $reason = null): ?VideoMatchState;

    public function releaseClaim(Uuid $stateId, Uuid $claimId): ?VideoMatchState;

    public function nextRequestDueAt(): ?DateTimeImmutable;

    /** @param int<1, max> $limit
     * @return list<VideoMatchState>
     */
    public function awaitingProviders(int $limit, ?Uuid $afterId = null): array;
}
