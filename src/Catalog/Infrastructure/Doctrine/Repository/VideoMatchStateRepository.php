<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Repository;

use App\Catalog\Domain\Model\VideoMatchState;
use App\Catalog\Domain\Repository\VideoMatchStateRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use LogicException;

/** SQL keeps upserts, SKIP LOCKED claims and token-fenced transitions in single statements. */
final readonly class VideoMatchStateRepository implements VideoMatchStateRepositoryInterface
{
    private const string COLUMNS = 'id, series_id, movie_id, status, source, due_at, claim_id, claim_expires_at, next_due_at, reason, best_score, checked_at';

    public function __construct(private Connection $connection) {}

    public function findBySeries(Uuid $seriesId): ?VideoMatchState
    {
        return $this->find('series_id', $seriesId);
    }

    public function findByMovie(Uuid $movieId): ?VideoMatchState
    {
        return $this->find('movie_id', $movieId);
    }

    public function requestSeries(Uuid $seriesId, string $source, DateTimeImmutable $dueAt): bool
    {
        return $this->request('series_id', $seriesId, 'requested', $source, $dueAt);
    }

    public function requestMovie(Uuid $movieId, string $source, DateTimeImmutable $dueAt): bool
    {
        return $this->request('movie_id', $movieId, 'requested', $source, $dueAt);
    }

    public function markAwaitingSeries(Uuid $seriesId, string $source, DateTimeImmutable $dueAt): bool
    {
        return $this->request('series_id', $seriesId, 'awaiting_provider', $source, $dueAt);
    }

    public function markAwaitingMovie(Uuid $movieId, string $source, DateTimeImmutable $dueAt): bool
    {
        return $this->request('movie_id', $movieId, 'awaiting_provider', $source, $dueAt);
    }

    public function recordAdminSeriesMatched(Uuid $seriesId, DateTimeImmutable $checkedAt): void
    {
        $this->recordAdminMatched('series_id', $seriesId, $checkedAt);
    }

    public function recordAdminMovieMatched(Uuid $movieId, DateTimeImmutable $checkedAt): void
    {
        $this->recordAdminMatched('movie_id', $movieId, $checkedAt);
    }

    public function cancelSeriesRematch(Uuid $seriesId, DateTimeImmutable $checkedAt, bool $matched = true): ?VideoMatchState
    {
        return $this->cancelRematch('series_id', $seriesId, $checkedAt, $matched);
    }

    public function cancelMovieRematch(Uuid $movieId, DateTimeImmutable $checkedAt, bool $matched = true): ?VideoMatchState
    {
        return $this->cancelRematch('movie_id', $movieId, $checkedAt, $matched);
    }

    public function claimOldestDueRequest(DateTimeImmutable $now, Uuid $claimId, int $leaseSeconds): ?VideoMatchState
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                UPDATE video_match_states AS claimed
                SET claim_id = :claim, claim_expires_at = clock_timestamp() + make_interval(secs => :lease), next_due_at = NULL
                WHERE claimed.id = (
                    SELECT id FROM video_match_states
                    WHERE status = 'requested' AND due_at <= :now
                      AND (claim_expires_at IS NULL OR claim_expires_at <= clock_timestamp())
                    ORDER BY due_at, id
                    LIMIT 1 FOR UPDATE SKIP LOCKED
                )
                RETURNING claimed.*
                SQL,
            ['claim' => $claimId, 'lease' => $leaseSeconds, 'now' => $now],
            ['claim' => 'uuid', 'lease' => ParameterType::INTEGER, 'now' => Types::DATETIME_IMMUTABLE],
        );

        return $row === false ? null : $this->toDomain($row);
    }

    public function lockClaimForApply(Uuid $stateId, Uuid $claimId): ?VideoMatchState
    {
        if (!$this->connection->isTransactionActive()) {
            throw new LogicException('A video match claim must be guarded inside its apply transaction.');
        }
        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . " FROM video_match_states WHERE id = :id AND claim_id = :claim AND status = 'requested' AND claim_expires_at > clock_timestamp() FOR UPDATE",
            ['id' => $stateId, 'claim' => $claimId],
            ['id' => 'uuid', 'claim' => 'uuid'],
        );

        return $row === false ? null : $this->toDomain($row);
    }

    public function completeClaim(Uuid $stateId, Uuid $claimId, DateTimeImmutable $checkedAt): ?VideoMatchState
    {
        return $this->endClaim($stateId, $claimId, 'matched', $checkedAt, checkedAt: $checkedAt);
    }

    public function markClaimedForReview(Uuid $stateId, Uuid $claimId, string $reason, ?int $bestScore, DateTimeImmutable $checkedAt): ?VideoMatchState
    {
        return $this->endClaim($stateId, $claimId, 'needs_review', $checkedAt, $reason, $bestScore, $checkedAt);
    }

    public function markClaimedAwaitingProvider(Uuid $stateId, Uuid $claimId, DateTimeImmutable $checkedAt): ?VideoMatchState
    {
        return $this->endClaim($stateId, $claimId, 'awaiting_provider', $checkedAt, checkedAt: $checkedAt);
    }

    public function postponeClaimedRequest(Uuid $stateId, Uuid $claimId, DateTimeImmutable $dueAt, ?string $reason = null): ?VideoMatchState
    {
        return $this->endClaim($stateId, $claimId, 'requested', $dueAt, $reason);
    }

    public function releaseClaim(Uuid $stateId, Uuid $claimId): ?VideoMatchState
    {
        return $this->endClaim($stateId, $claimId, 'requested');
    }

    public function nextRequestDueAt(): ?DateTimeImmutable
    {
        $value = $this->connection->fetchOne(
            "SELECT min(CASE WHEN claim_expires_at > clock_timestamp() THEN GREATEST(due_at, claim_expires_at) ELSE due_at END) FROM video_match_states WHERE status = 'requested'",
        );

        return $value === false ? null : $this->instant($value);
    }

    public function awaitingProviders(int $limit, ?Uuid $afterId = null): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . " FROM video_match_states WHERE status = 'awaiting_provider' AND (CAST(:after AS uuid) IS NULL OR id > :after) ORDER BY id LIMIT :limit",
            ['after' => $afterId, 'limit' => $limit],
            ['after' => 'uuid', 'limit' => ParameterType::INTEGER],
        );

        return array_map($this->toDomain(...), $rows);
    }

    /** @param 'series_id'|'movie_id' $column */
    private function find(string $column, Uuid $titleId): ?VideoMatchState
    {
        $row = $this->connection->fetchAssociative(
            sprintf('SELECT %s FROM video_match_states WHERE %s = :title', self::COLUMNS, $column),
            ['title' => $titleId],
            ['title' => 'uuid'],
        );

        return $row === false ? null : $this->toDomain($row);
    }

    /** @param 'series_id'|'movie_id' $column */
    private function request(string $column, Uuid $titleId, string $status, string $source, DateTimeImmutable $dueAt): bool
    {
        return $this->connection->fetchOne(
            sprintf(<<<'SQL'
                INSERT INTO video_match_states AS existing (id, %s, status, source, due_at)
                VALUES (:id, :title, :status, :source, :due)
                ON CONFLICT (%s) DO UPDATE
                SET source = CASE WHEN existing.status IN ('requested', 'awaiting_provider') AND existing.source = 'admin' THEN existing.source ELSE EXCLUDED.source END,
                    reason = NULL, best_score = NULL, checked_at = NULL,
                    (status, due_at, claim_id, claim_expires_at, next_due_at) = (
                        WITH lease AS MATERIALIZED (SELECT existing.claim_expires_at > clock_timestamp() AS live)
                        SELECT CASE WHEN lease.live THEN existing.status ELSE EXCLUDED.status END,
                               CASE WHEN lease.live THEN existing.due_at
                                    WHEN existing.status = EXCLUDED.status THEN LEAST(existing.due_at, EXCLUDED.due_at)
                                    ELSE EXCLUDED.due_at END,
                               CASE WHEN lease.live THEN existing.claim_id END,
                               CASE WHEN lease.live THEN existing.claim_expires_at END,
                               CASE WHEN lease.live THEN LEAST(existing.next_due_at, EXCLUDED.due_at) END
                        FROM lease
                    )
                RETURNING (old.id IS NULL OR old.status IS DISTINCT FROM new.status OR new.due_at < old.due_at)
                          AND new.claim_id IS NULL
                SQL, $column, $column),
            ['id' => Uuid::v7(), 'title' => $titleId, 'status' => $status, 'source' => $source, 'due' => $dueAt],
            ['id' => 'uuid', 'title' => 'uuid', 'due' => Types::DATETIME_IMMUTABLE],
        ) === true;
    }

    private function endClaim(
        Uuid $stateId,
        Uuid $claimId,
        string $status,
        ?DateTimeImmutable $dueAt = null,
        ?string $reason = null,
        ?int $bestScore = null,
        ?DateTimeImmutable $checkedAt = null,
    ): ?VideoMatchState {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                UPDATE video_match_states
                SET status = CASE WHEN next_due_at IS NULL THEN :status ELSE 'requested' END,
                    due_at = COALESCE(next_due_at, CAST(:due AS timestamptz), due_at),
                    reason = CASE WHEN next_due_at IS NULL THEN :reason END,
                    best_score = CASE WHEN next_due_at IS NULL THEN CAST(:score AS smallint) END,
                    checked_at = CASE WHEN next_due_at IS NULL THEN CAST(:checked AS timestamptz) END,
                    claim_id = NULL, claim_expires_at = NULL, next_due_at = NULL
                WHERE id = :id AND claim_id = :claim AND claim_expires_at > clock_timestamp()
                RETURNING *
                SQL,
            ['id' => $stateId, 'claim' => $claimId, 'status' => $status, 'due' => $dueAt, 'reason' => $reason, 'score' => $bestScore, 'checked' => $checkedAt],
            ['id' => 'uuid', 'claim' => 'uuid', 'due' => Types::DATETIME_IMMUTABLE, 'checked' => Types::DATETIME_IMMUTABLE],
        );

        return $row === false ? null : $this->toDomain($row);
    }

    /** @param 'series_id'|'movie_id' $column */
    private function recordAdminMatched(string $column, Uuid $titleId, DateTimeImmutable $checkedAt): void
    {
        $this->connection->executeStatement(
            sprintf(<<<'SQL'
                INSERT INTO video_match_states (id, %s, status, source, due_at, checked_at)
                VALUES (:id, :title, 'matched', 'admin', :checked, :checked)
                ON CONFLICT (%s) DO UPDATE
                SET status = 'matched', source = 'admin', due_at = EXCLUDED.due_at, checked_at = EXCLUDED.checked_at,
                    claim_id = NULL, claim_expires_at = NULL, next_due_at = NULL, reason = NULL, best_score = NULL
                SQL, $column, $column),
            ['id' => Uuid::v7(), 'title' => $titleId, 'checked' => $checkedAt],
            ['id' => 'uuid', 'title' => 'uuid', 'checked' => Types::DATETIME_IMMUTABLE],
        );
    }

    /** @param 'series_id'|'movie_id' $column */
    private function cancelRematch(string $column, Uuid $titleId, DateTimeImmutable $checkedAt, bool $matched): ?VideoMatchState
    {
        $row = $this->connection->fetchAssociative(
            sprintf(<<<'SQL'
                UPDATE video_match_states
                SET status = :status, source = :source, due_at = :checked, checked_at = :checked,
                    claim_id = NULL, claim_expires_at = NULL, next_due_at = NULL, reason = NULL, best_score = NULL
                WHERE %s = :title AND status = 'requested' AND source = 'admin'
                RETURNING *
                SQL, $column),
            ['title' => $titleId, 'status' => $matched ? 'matched' : 'requested', 'source' => $matched ? 'admin' : 'ingest', 'checked' => $checkedAt],
            ['title' => 'uuid', 'checked' => Types::DATETIME_IMMUTABLE],
        );

        return $row === false ? null : $this->toDomain($row);
    }

    /** @param array<string, mixed> $row */
    private function toDomain(array $row): VideoMatchState
    {
        return new VideoMatchState(
            id: Uuid::fromString((string) $row['id']),
            seriesId: $row['series_id'] === null ? null : Uuid::fromString((string) $row['series_id']),
            movieId: $row['movie_id'] === null ? null : Uuid::fromString((string) $row['movie_id']),
            status: (string) $row['status'],
            source: (string) $row['source'],
            dueAt: $this->instant($row['due_at']) ?? throw new \UnexpectedValueException('A video match state has no due time.'),
            claimId: $row['claim_id'] === null ? null : Uuid::fromString((string) $row['claim_id']),
            claimExpiresAt: $this->instant($row['claim_expires_at']),
            nextDueAt: $this->instant($row['next_due_at']),
            reason: $row['reason'] === null ? null : (string) $row['reason'],
            bestScore: $row['best_score'] === null ? null : (int) $row['best_score'],
            checkedAt: $this->instant($row['checked_at']),
        );
    }

    private function instant(mixed $value): ?DateTimeImmutable
    {
        return $this->connection->convertToPHPValue($value, Types::DATETIME_IMMUTABLE);
    }
}
