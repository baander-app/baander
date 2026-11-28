<?php

declare(strict_types=1);

namespace App\Activity\Infrastructure\Doctrine\Repository;

use App\Activity\Domain\Model\WatchedMark;
use App\Activity\Domain\Model\WatchedMarkState;
use App\Activity\Domain\Repository\WatchedMarkRepositoryInterface;
use App\Activity\Infrastructure\Doctrine\Entity\WatchedMarkEntity;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;

final readonly class WatchedMarkRepository implements WatchedMarkRepositoryInterface
{
    public function __construct(private Connection $connection, private EntityManagerInterface $manager) {}

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

    public function saveMany(array $marks): void
    {
        $this->requireTransaction();
        $groups = ['episode_id' => [], 'movie_id' => []];
        foreach ($marks as $mark) {
            $column = $mark->getEpisodeId() === null ? 'movie_id' : 'episode_id';
            $target = $mark->getEpisodeId() ?? $mark->getMovieId();
            $key = $target . ':' . $mark->getUserId();
            $previous = $groups[$column][$key] ?? null;
            if ($previous === null || $mark->getWatchedAt() > $previous->getWatchedAt()) {
                $groups[$column][$key] = $mark;
            }
        }
        foreach ($groups as $column => $targets) {
            ksort($targets, SORT_STRING);
            foreach (array_chunk($targets, 500) as $chunk) {
                $parameters = [];
                $types = [];
                foreach ($chunk as $mark) {
                    array_push(
                        $parameters,
                        $mark->getId(),
                        $mark->getUserId(),
                        $mark->getEpisodeId(),
                        $mark->getMovieId(),
                        $mark->getWatchedAt(),
                    );
                    array_push($types, 'uuid', 'uuid', 'uuid', 'uuid', 'datetime_immutable');
                }
                $count = count($chunk);
                $values = array_fill(0, $count, '(?, ?, ?, ?, ?)') |> (static fn (array $rows): string => implode(', ', $rows));
                // DQL has no bulk upsert; duplicate member/target marks retain the latest watched instant.
                $this->connection->executeStatement(
                    'INSERT INTO watched_marks (id, user_id, episode_id, movie_id, watched_at) VALUES '
                    . $values
                    . ' ON CONFLICT (user_id, ' . $column . ') DO UPDATE SET watched_at = GREATEST(watched_marks.watched_at, EXCLUDED.watched_at)',
                    $parameters,
                    $types,
                );
            }
        }
    }

    public function deleteForMemberTargets(Uuid $userId, array $episodeIds, array $movieIds): void
    {
        $this->requireTransaction();
        foreach (['episodeId' => $episodeIds, 'movieId' => $movieIds] as $field => $ids) {
            if ($ids === []) {
                continue;
            }
            $this->manager->createQuery(
                'DELETE FROM ' . WatchedMarkEntity::class . ' mark WHERE mark.userId = :user AND mark.' . $field . ' IN (:ids)',
            )
                ->setParameter('user', $userId, 'uuid')
                ->setParameter('ids', array_map(static fn (Uuid $id): string => $id->toString(), $ids), ArrayParameterType::STRING)
                ->execute();
        }
    }

    private function requireTransaction(): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new LogicException('Member watched marks must be written inside the title transaction.');
        }
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
