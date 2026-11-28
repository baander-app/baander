<?php

declare(strict_types=1);

namespace App\Activity\Infrastructure\Doctrine;

use App\Activity\Application\DTO\VideoResumeState;
use App\Activity\Application\DTO\ViewingState as ViewingStateSnapshot;
use App\Activity\Application\Port\ViewingStatePortInterface;
use App\Activity\Domain\Model\ResumePosition;
use App\Activity\Domain\Model\ResumePositionState;
use App\Activity\Infrastructure\Doctrine\Entity\ResumePositionEntity;
use App\Activity\Infrastructure\Doctrine\Entity\WatchedMarkEntity;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ViewingState implements ViewingStatePortInterface
{
    public function __construct(private EntityManagerInterface $manager) {}

    public function forMember(
        Uuid $userId,
        array $episodeIds = [],
        array $movieIds = [],
        array $videoIds = [],
    ): ViewingStateSnapshot {
        $resumes = [];
        if ($videoIds !== []) {
            $rows = $this->manager->createQuery(
                'SELECT position.id, position.userId, position.videoId, position.positionSeconds, position.reportedAt, position.lastPlayedAt'
                . ' FROM ' . ResumePositionEntity::class . ' position WHERE position.userId = :user AND position.videoId IN (:videos) ORDER BY position.videoId',
            )
                ->setParameter('user', $userId, 'uuid')
                ->setParameter('videos', self::strings($videoIds), ArrayParameterType::STRING)
                ->getArrayResult();
            foreach ($rows as $row) {
                $position = ResumePosition::reconstitute(new ResumePositionState(
                    $row['id'],
                    $row['userId'],
                    $row['videoId'],
                    $row['positionSeconds'],
                    $row['reportedAt'],
                    $row['lastPlayedAt'],
                ));
                $resumes[$position->getVideoId()->toString()] = new VideoResumeState(
                    $position->getVideoId(),
                    $position->getPositionSeconds(),
                    $position->getLastPlayedAt(),
                );
            }
        }

        return new ViewingStateSnapshot(
            $this->watchedIds($userId, $episodeIds, 'episode_id'),
            $this->watchedIds($userId, $movieIds, 'movie_id'),
            $resumes,
        );
    }

    /**
     * @param list<Uuid> $ids
     * @param 'episode_id'|'movie_id' $column
     * @return list<Uuid>
     */
    private function watchedIds(Uuid $userId, array $ids, string $column): array
    {
        if ($ids === []) {
            return [];
        }

        $field = $column === 'episode_id' ? 'episodeId' : 'movieId';
        $rows = $this->manager->createQuery(
            'SELECT mark.' . $field . ' AS target FROM ' . WatchedMarkEntity::class . ' mark'
            . ' WHERE mark.userId = :user AND mark.' . $field . ' IN (:ids) ORDER BY mark.' . $field,
        )
            ->setParameter('user', $userId, 'uuid')
            ->setParameter('ids', self::strings($ids), ArrayParameterType::STRING)
            ->getArrayResult();

        return array_column($rows, 'target');
    }

    /**
     * @param list<Uuid> $ids
     * @return list<string>
     */
    private static function strings(array $ids): array
    {
        return array_map(static fn (Uuid $id): string => $id->toString(), $ids)
            |> array_unique(...)
            |> array_values(...);
    }
}
