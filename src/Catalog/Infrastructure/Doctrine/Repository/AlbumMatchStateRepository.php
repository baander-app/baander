<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Repository;

use App\Catalog\Domain\Repository\AlbumMatchStateRepositoryInterface;
use App\Catalog\Domain\ValueObject\AlbumMatchReviewReason;
use App\Catalog\Domain\ValueObject\AlbumMatchSource;
use App\Catalog\Domain\ValueObject\AlbumMatchState;
use App\Catalog\Domain\ValueObject\AlbumMatchStatus;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * Reads and writes album_match_states (mapped by AlbumMatchStateEntity) with single statements.
 * The row's key is its album, so the entity is never loaded: Doctrine would hold an
 * association key as the raw column text, which the UUID type refuses on a later update.
 */
final readonly class AlbumMatchStateRepository implements AlbumMatchStateRepositoryInterface
{
    private const array TYPES = [
        'dueAt' => Types::DATETIME_IMMUTABLE,
        'checkedAt' => Types::DATETIME_IMMUTABLE,
    ];

    public function __construct(
        private Connection $connection,
    ) {
    }

    #[\Override]
    public function findByAlbum(Uuid $albumId): ?AlbumMatchState
    {
        $row = $this->connection->fetchAssociative(
            'SELECT album_id, state, source, due_at, reason, checked_song_count, checked_at, best_score FROM album_match_states WHERE album_id = :albumId',
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

    private function instant(mixed $value): ?\DateTimeImmutable
    {
        return $this->connection->convertToPHPValue($value, Types::DATETIME_IMMUTABLE);
    }
}
