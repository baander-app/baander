<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Repository;

use App\Catalog\Domain\Repository\AlbumMatchStateRepositoryInterface;
use App\Catalog\Domain\ValueObject\AlbumMatchReviewReason;
use App\Catalog\Domain\ValueObject\AlbumMatchSource;
use App\Catalog\Domain\ValueObject\AlbumMatchState;
use App\Catalog\Domain\ValueObject\AlbumMatchStatus;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;

/**
 * Reads and writes album_match_states (mapped by AlbumMatchStateEntity) with single statements.
 * The row's key is its album, so the entity is never loaded: Doctrine would hold an
 * association key as the raw column text, which the UUID type refuses on a later update.
 * The queue's statements are SQL for a further reason: DQL has no upsert, no MERGE, no RETURNING
 * of the row's previous values and no SKIP LOCKED, and each decision must stay in its one statement.
 *
 * A claim's expiry is written and judged on the database clock (clock_timestamp(), which also
 * moves inside a transaction); due times come from the application clock that also reads them.
 * The statements rely on READ COMMITTED: one that waited for a row another session changed acts
 * on the row as that session left it.
 */
final readonly class AlbumMatchStateRepository implements AlbumMatchStateRepositoryInterface
{
    private const string COLUMNS = 'album_id, state, source, due_at, reason, checked_song_count, checked_at, best_score';

    /** When a run can take a request: at its due time, or when the live claim on it expires. */
    private const string CLAIMABLE_FROM = 'CASE WHEN claim_expires_at > clock_timestamp() THEN claim_expires_at ELSE due_at END';

    private const array TYPES = [
        'dueAt' => Types::DATETIME_IMMUTABLE,
        'checkedAt' => Types::DATETIME_IMMUTABLE,
        'now' => Types::DATETIME_IMMUTABLE,
        'lease' => ParameterType::INTEGER,
    ];

    public function __construct(
        private Connection $connection,
    ) {
    }

    #[\Override]
    public function findByAlbum(Uuid $albumId): ?AlbumMatchState
    {
        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . ' FROM album_match_states WHERE album_id = :albumId',
            ['albumId' => $albumId->toString()],
        );

        return $row === false ? null : $this->toDomain($row);
    }

    #[\Override]
    public function removeByAlbum(Uuid $albumId): void
    {
        $this->connection->executeStatement(
            'DELETE FROM album_match_states WHERE album_id = :albumId',
            ['albumId' => $albumId->toString()],
        );
    }

    #[\Override]
    public function request(Uuid $albumId, AlbumMatchSource $source, DateTimeImmutable $dueAt): bool
    {
        // The update reads the row as the conflict found it, after any wait for a claim or an
        // outcome in flight. Whether the claim is live is sampled once, so the four columns that
        // depend on it agree: a live claim keeps the due time and the claim and takes the request
        // beside it, any other row takes the request itself. RETURNING old (PostgreSQL 18)
        // compares the row with what it was before: a new row has no old state, and an album that
        // waited keeps the earlier due time, so its due time moved forward only when the stored
        // one is now earlier than before.
        return $this->connection->fetchOne(
            <<<'SQL'
                INSERT INTO album_match_states AS existing (album_id, state, source, due_at)
                VALUES (:albumId, :requested, :source, :dueAt)
                ON CONFLICT (album_id) DO UPDATE
                   SET state = EXCLUDED.state,
                       source = CASE WHEN existing.state = :requested AND existing.source = :admin THEN existing.source ELSE EXCLUDED.source END,
                       reason = NULL,
                       checked_song_count = NULL,
                       checked_at = NULL,
                       best_score = NULL,
                       (due_at, claim_id, claim_expires_at, next_due_at) = (
                           WITH claim AS MATERIALIZED (SELECT existing.claim_expires_at > clock_timestamp() AS live)
                           SELECT CASE WHEN claim.live THEN existing.due_at
                                       WHEN existing.state = :requested THEN LEAST(existing.due_at, EXCLUDED.due_at)
                                       ELSE EXCLUDED.due_at
                                   END,
                                  CASE WHEN claim.live THEN existing.claim_id END,
                                  CASE WHEN claim.live THEN existing.claim_expires_at END,
                                  CASE WHEN claim.live THEN LEAST(existing.next_due_at, EXCLUDED.due_at) END
                             FROM claim
                       )
                RETURNING old.state IS DISTINCT FROM :requested OR new.due_at < old.due_at AS due_sooner
                SQL,
            [
                'albumId' => $albumId->toString(),
                'requested' => AlbumMatchStatus::Requested->value,
                'admin' => AlbumMatchSource::Admin->value,
                'source' => $source->value,
                'dueAt' => $dueAt,
            ],
            self::TYPES,
        ) === true;
    }

    #[\Override]
    public function hasRequestsDueBy(DateTimeImmutable $dueAt, Uuid ...$albumIds): bool
    {
        return $this->connection->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM album_match_states WHERE state = :requested AND ' . self::CLAIMABLE_FROM . ' <= :dueAt AND album_id <> ALL (CAST(:albumIds AS uuid[])))',
            [
                'requested' => AlbumMatchStatus::Requested->value,
                'dueAt' => $dueAt,
                'albumIds' => '{' . implode(',', array_map(static fn (Uuid $id): string => $id->toString(), $albumIds)) . '}',
            ],
            self::TYPES,
        ) === true;
    }

    #[\Override]
    public function nextRequestDueAt(): ?DateTimeImmutable
    {
        return $this->instant($this->connection->fetchOne(
            'SELECT min(' . self::CLAIMABLE_FROM . ') FROM album_match_states WHERE state = :requested',
            ['requested' => AlbumMatchStatus::Requested->value],
        ));
    }

    #[\Override]
    public function claimOldestDueRequest(DateTimeImmutable $now, Uuid $claimId, int $leaseSeconds): ?AlbumMatchState
    {
        // SKIP LOCKED passes over a request another run is claiming in this instant. A request that
        // run claimed and committed since this statement's snapshot is locked in its new version,
        // which fails the claim condition and is passed over too.
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                UPDATE album_match_states AS claimed
                   SET claim_id = :claimId,
                       claim_expires_at = clock_timestamp() + make_interval(secs => :lease),
                       next_due_at = NULL
                 WHERE claimed.album_id = (
                        SELECT album_id
                          FROM album_match_states
                         WHERE state = :requested AND due_at <= :now
                           AND (claim_expires_at IS NULL OR claim_expires_at <= clock_timestamp())
                         ORDER BY due_at, album_id
                         LIMIT 1
                           FOR UPDATE SKIP LOCKED
                       )
                RETURNING claimed.album_id, claimed.state, claimed.source, claimed.due_at, claimed.reason,
                          claimed.checked_song_count, claimed.checked_at, claimed.best_score
                SQL,
            [
                'claimId' => $claimId->toString(),
                'lease' => $leaseSeconds,
                'requested' => AlbumMatchStatus::Requested->value,
                'now' => $now,
            ],
            self::TYPES,
        );

        return $row === false ? null : $this->toDomain($row);
    }

    #[\Override]
    public function removeClaimedRequest(Uuid $albumId, Uuid $claimId): ?AlbumMatchState
    {
        // One MERGE, because a request can commit between this statement's snapshot and its write.
        // The statement then waits for the row, and READ COMMITTED has MERGE evaluate its WHEN
        // clauses again on the row as the request left it, from the first clause on: the row that
        // looked removable is kept and left waiting. A DELETE and an UPDATE as two parts of one
        // statement share the first snapshot instead, so the UPDATE never sees the request and the
        // DELETE passes the changed row over, which leaves the claim on it until it lapses.
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                MERGE INTO album_match_states AS held
                USING (VALUES (CAST(:albumId AS uuid), CAST(:claimId AS uuid))) AS claim (album_id, claim_id)
                   ON held.album_id = claim.album_id AND held.claim_id = claim.claim_id
                 WHEN MATCHED AND held.next_due_at IS NULL THEN DELETE
                 WHEN MATCHED THEN UPDATE SET due_at = held.next_due_at, claim_id = NULL, claim_expires_at = NULL, next_due_at = NULL
                RETURNING merge_action() AS action, held.album_id, held.state, held.source, held.due_at, held.reason,
                          held.checked_song_count, held.checked_at, held.best_score
                SQL,
            ['albumId' => $albumId->toString(), 'claimId' => $claimId->toString()],
        );

        return $row === false || $row['action'] === 'DELETE' ? null : $this->toDomain($row);
    }

    #[\Override]
    public function markClaimedForReview(
        Uuid $albumId,
        Uuid $claimId,
        AlbumMatchReviewReason $reason,
        ?int $bestScore,
        int $checkedSongCount,
        DateTimeImmutable $checkedAt,
    ): ?AlbumMatchState {
        // Every assignment reads next_due_at as it was before this statement, so the statement
        // that finds a request beside the claim is the one that leaves the album waiting for it.
        return $this->endClaim(
            <<<'SQL'
                UPDATE album_match_states
                   SET state = CASE WHEN next_due_at IS NULL THEN :needsReview ELSE state END,
                       due_at = COALESCE(next_due_at, CAST(:checkedAt AS timestamptz)),
                       reason = CASE WHEN next_due_at IS NULL THEN :reason END,
                       best_score = CASE WHEN next_due_at IS NULL THEN CAST(:bestScore AS smallint) END,
                       checked_song_count = CASE WHEN next_due_at IS NULL THEN CAST(:checkedSongCount AS integer) END,
                       checked_at = CASE WHEN next_due_at IS NULL THEN CAST(:checkedAt AS timestamptz) END,
                       claim_id = NULL, claim_expires_at = NULL, next_due_at = NULL
                 WHERE album_id = :albumId AND claim_id = :claimId
                SQL,
            $albumId,
            $claimId,
            [
                'needsReview' => AlbumMatchStatus::NeedsReview->value,
                'checkedAt' => $checkedAt,
                'reason' => $reason->value,
                'bestScore' => $bestScore,
                'checkedSongCount' => $checkedSongCount,
            ],
        );
    }

    #[\Override]
    public function postponeClaimedRequest(Uuid $albumId, Uuid $claimId, DateTimeImmutable $dueAt): ?AlbumMatchState
    {
        return $this->endClaim(
            <<<'SQL'
                UPDATE album_match_states
                   SET due_at = COALESCE(next_due_at, :dueAt),
                       claim_id = NULL, claim_expires_at = NULL, next_due_at = NULL
                 WHERE album_id = :albumId AND claim_id = :claimId
                SQL,
            $albumId,
            $claimId,
            ['dueAt' => $dueAt],
        );
    }

    #[\Override]
    public function releaseClaim(Uuid $albumId, Uuid $claimId): ?AlbumMatchState
    {
        return $this->endClaim(
            <<<'SQL'
                UPDATE album_match_states
                   SET claim_id = NULL, claim_expires_at = NULL, next_due_at = NULL
                 WHERE album_id = :albumId AND claim_id = :claimId
                SQL,
            $albumId,
            $claimId,
        );
    }

    #[\Override]
    public function removeIngestRequests(): void
    {
        $this->connection->executeStatement(
            'DELETE FROM album_match_states WHERE state = :requested AND source = :ingest',
            ['requested' => AlbumMatchStatus::Requested->value, 'ingest' => AlbumMatchSource::Ingest->value],
        );
    }

    /**
     * Runs an update that ends the claim on the row the claim still holds, and returns the state
     * it left the album in. The claim ID alone decides: a lapsed claim holds its row until another
     * run or a request takes it. An update that waited for a request in flight sets its values
     * from the row as that request left it.
     *
     * @param array<string, mixed> $parameters the parameters the update names besides the album and the claim
     */
    private function endClaim(string $update, Uuid $albumId, Uuid $claimId, array $parameters = []): ?AlbumMatchState
    {
        $row = $this->connection->fetchAssociative(
            $update . ' RETURNING ' . self::COLUMNS,
            ['albumId' => $albumId->toString(), 'claimId' => $claimId->toString(), ...$parameters],
            self::TYPES,
        );

        return $row === false ? null : $this->toDomain($row);
    }

    /** @param array<string, mixed> $row */
    private function toDomain(array $row): AlbumMatchState
    {
        return new AlbumMatchState(
            albumId: Uuid::fromString((string) $row['album_id']),
            status: AlbumMatchStatus::from((string) $row['state']),
            source: AlbumMatchSource::from((string) $row['source']),
            dueAt: $this->instant($row['due_at']) ?? throw new \UnexpectedValueException('An album match state has no due time.'),
            reason: $row['reason'] === null ? null : AlbumMatchReviewReason::from((string) $row['reason']),
            checkedSongCount: $row['checked_song_count'] === null ? null : (int) $row['checked_song_count'],
            checkedAt: $this->instant($row['checked_at']),
            bestScore: $row['best_score'] === null ? null : (int) $row['best_score'],
        );
    }

    private function instant(mixed $value): ?DateTimeImmutable
    {
        return $this->connection->convertToPHPValue($value, Types::DATETIME_IMMUTABLE);
    }
}
