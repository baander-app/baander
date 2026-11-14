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
use Doctrine\DBAL\Types\Types;

/**
 * Reads and writes album_match_states (mapped by AlbumMatchStateEntity) with single statements.
 * The row's key is its album, so the entity is never loaded: Doctrine would hold an
 * association key as the raw column text, which the UUID type refuses on a later update.
 * The queue's statements are SQL for a further reason: DQL has no upsert, no RETURNING of the
 * row's previous values and no SKIP LOCKED, and each decision must stay in its one statement.
 */
final readonly class AlbumMatchStateRepository implements AlbumMatchStateRepositoryInterface
{
    private const string COLUMNS = 'album_id, state, source, due_at, reason, checked_song_count, checked_at, best_score';

    private const array TYPES = [
        'dueAt' => Types::DATETIME_IMMUTABLE,
        'checkedAt' => Types::DATETIME_IMMUTABLE,
        'claimedUntil' => Types::DATETIME_IMMUTABLE,
        'now' => Types::DATETIME_IMMUTABLE,
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
    public function save(AlbumMatchState $state): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO album_match_states (album_id, state, source, due_at, reason, checked_song_count, checked_at, best_score)
                VALUES (:albumId, :state, :source, :dueAt, :reason, :checkedSongCount, :checkedAt, :bestScore)
                ON CONFLICT (album_id) DO UPDATE
                   SET state = EXCLUDED.state,
                       source = EXCLUDED.source,
                       due_at = EXCLUDED.due_at,
                       reason = EXCLUDED.reason,
                       checked_song_count = EXCLUDED.checked_song_count,
                       checked_at = EXCLUDED.checked_at,
                       best_score = EXCLUDED.best_score
                SQL,
            [
                'albumId' => $state->albumId->toString(),
                'state' => $state->status->value,
                'source' => $state->source->value,
                'dueAt' => $state->dueAt,
                'reason' => $state->reason?->value,
                'checkedSongCount' => $state->checkedSongCount,
                'checkedAt' => $state->checkedAt,
                'bestScore' => $state->bestScore,
            ],
            self::TYPES,
        );
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
        // RETURNING old (PostgreSQL 18) names the state the album was in before this request:
        // none for a new row, so the caller learns whether the queue gained an album.
        $previous = $this->connection->fetchAssociative(
            <<<'SQL'
                INSERT INTO album_match_states AS existing (album_id, state, source, due_at)
                VALUES (:albumId, :requested, :source, :dueAt)
                ON CONFLICT (album_id) DO UPDATE
                   SET state = EXCLUDED.state,
                       source = CASE WHEN existing.state = :requested AND existing.source = :admin THEN existing.source ELSE EXCLUDED.source END,
                       due_at = CASE WHEN existing.state = :requested THEN LEAST(existing.due_at, EXCLUDED.due_at) ELSE EXCLUDED.due_at END,
                       reason = NULL,
                       checked_song_count = NULL,
                       checked_at = NULL,
                       best_score = NULL
                RETURNING old.state AS previous_state
                SQL,
            [
                'albumId' => $albumId->toString(),
                'requested' => AlbumMatchStatus::Requested->value,
                'admin' => AlbumMatchSource::Admin->value,
                'source' => $source->value,
                'dueAt' => $dueAt,
            ],
            self::TYPES,
        );

        return ($previous['previous_state'] ?? null) !== AlbumMatchStatus::Requested->value;
    }

    #[\Override]
    public function hasRequestsOtherThan(Uuid ...$albumIds): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM album_match_states WHERE state = :requested AND album_id <> ALL (CAST(:albumIds AS uuid[])))',
            [
                'requested' => AlbumMatchStatus::Requested->value,
                'albumIds' => '{' . implode(',', array_map(static fn (Uuid $id): string => $id->toString(), $albumIds)) . '}',
            ],
        );
    }

    #[\Override]
    public function nextRequestDueAt(): ?DateTimeImmutable
    {
        return $this->instant($this->connection->fetchOne(
            'SELECT min(due_at) FROM album_match_states WHERE state = :requested',
            ['requested' => AlbumMatchStatus::Requested->value],
        ));
    }

    #[\Override]
    public function claimOldestDueRequest(DateTimeImmutable $now, DateTimeImmutable $claimedUntil): ?AlbumMatchState
    {
        // SKIP LOCKED passes over a request another run is claiming in this instant; RETURNING
        // old.due_at gives back the due time the claim replaced.
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                UPDATE album_match_states AS claimed
                   SET due_at = :claimedUntil
                 WHERE claimed.album_id = (
                        SELECT album_id
                          FROM album_match_states
                         WHERE state = :requested AND due_at <= :now
                         ORDER BY due_at, album_id
                         LIMIT 1
                           FOR UPDATE SKIP LOCKED
                       )
                RETURNING claimed.album_id, claimed.state, claimed.source, old.due_at AS due_at, claimed.reason,
                          claimed.checked_song_count, claimed.checked_at, claimed.best_score
                SQL,
            [
                'claimedUntil' => $claimedUntil,
                'requested' => AlbumMatchStatus::Requested->value,
                'now' => $now,
            ],
            self::TYPES,
        );

        return $row === false ? null : $this->toDomain($row);
    }

    #[\Override]
    public function releaseClaim(AlbumMatchState $request, DateTimeImmutable $claimedUntil): void
    {
        $this->connection->executeStatement(
            'UPDATE album_match_states SET due_at = :dueAt WHERE album_id = :albumId AND state = :requested AND due_at = :claimedUntil',
            [
                'dueAt' => $request->dueAt,
                'albumId' => $request->albumId->toString(),
                'requested' => AlbumMatchStatus::Requested->value,
                'claimedUntil' => $claimedUntil,
            ],
            self::TYPES,
        );
    }

    #[\Override]
    public function removeClaimedRequest(Uuid $albumId, DateTimeImmutable $claimedUntil): void
    {
        $this->connection->executeStatement(
            'DELETE FROM album_match_states WHERE album_id = :albumId AND state = :requested AND due_at = :claimedUntil',
            [
                'albumId' => $albumId->toString(),
                'requested' => AlbumMatchStatus::Requested->value,
                'claimedUntil' => $claimedUntil,
            ],
            self::TYPES,
        );
    }

    #[\Override]
    public function markClaimedForReview(
        Uuid $albumId,
        DateTimeImmutable $claimedUntil,
        AlbumMatchReviewReason $reason,
        ?int $bestScore,
        int $checkedSongCount,
        DateTimeImmutable $checkedAt,
    ): bool {
        return $this->connection->executeStatement(
            <<<'SQL'
                UPDATE album_match_states
                   SET state = :needsReview,
                       due_at = :checkedAt,
                       reason = :reason,
                       best_score = :bestScore,
                       checked_song_count = :checkedSongCount,
                       checked_at = :checkedAt
                 WHERE album_id = :albumId AND state = :requested AND due_at = :claimedUntil
                SQL,
            [
                'needsReview' => AlbumMatchStatus::NeedsReview->value,
                'checkedAt' => $checkedAt,
                'reason' => $reason->value,
                'bestScore' => $bestScore,
                'checkedSongCount' => $checkedSongCount,
                'albumId' => $albumId->toString(),
                'requested' => AlbumMatchStatus::Requested->value,
                'claimedUntil' => $claimedUntil,
            ],
            self::TYPES,
        ) > 0;
    }

    #[\Override]
    public function removeIngestRequests(): void
    {
        $this->connection->executeStatement(
            'DELETE FROM album_match_states WHERE state = :requested AND source = :ingest',
            ['requested' => AlbumMatchStatus::Requested->value, 'ingest' => AlbumMatchSource::Ingest->value],
        );
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
