<?php

declare(strict_types=1);

namespace App\Activity\Infrastructure\Doctrine\Repository;

use App\Activity\Domain\Model\WatchedMark;
use App\Activity\Domain\Model\WatchedMarkState;
use App\Activity\Domain\Repository\WatchedMarkRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class WatchedMarkRepository implements WatchedMarkRepositoryInterface
{
    public function __construct(private Connection $connection) {}

    public function findByEpisodeIds(array $episodeIds): array
    {
        if ($episodeIds === []) {
            return [];
        }
        // Read plain snapshots under deterministic row locks; managed entities would outlive the remap.
        return array_map($this->hydrate(...), $this->connection->fetchAllAssociative(
            'SELECT * FROM watched_marks WHERE episode_id IN (?) ORDER BY episode_id, user_id FOR UPDATE',
            [array_map(static fn (Uuid $id): string => $id->toString(), $episodeIds)],
            [ArrayParameterType::STRING],
        ));
    }

    public function findForMovie(Uuid $userId, Uuid $movieId): ?WatchedMark
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM watched_marks WHERE user_id = ? AND movie_id = ?',
            [$userId, $movieId],
            ['uuid', 'uuid'],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function save(WatchedMark $mark): void
    {
        $target = $mark->getEpisodeId() === null ? 'movie_id' : 'episode_id';
        // DQL has no upsert; concurrent copies merge by member and target without losing the later mark.
        $this->connection->executeStatement(
            'INSERT INTO watched_marks (id, user_id, episode_id, movie_id, watched_at) VALUES (?, ?, ?, ?, ?) '
            . 'ON CONFLICT (user_id, ' . $target . ') DO UPDATE SET watched_at = GREATEST(watched_marks.watched_at, EXCLUDED.watched_at)',
            [$mark->getId(), $mark->getUserId(), $mark->getEpisodeId(), $mark->getMovieId(), $mark->getWatchedAt()],
            ['uuid', 'uuid', 'uuid', 'uuid', 'datetime_immutable'],
        );
    }

    public function delete(WatchedMark $mark): void
    {
        $this->connection->delete('watched_marks', ['id' => $mark->getId()], ['id' => 'uuid']);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): WatchedMark
    {
        return WatchedMark::reconstitute(new WatchedMarkState(
            Uuid::fromString($row['id']),
            Uuid::fromString($row['user_id']),
            $row['episode_id'] === null ? null : Uuid::fromString($row['episode_id']),
            $row['movie_id'] === null ? null : Uuid::fromString($row['movie_id']),
            new DateTimeImmutable($row['watched_at']),
        ));
    }
}
