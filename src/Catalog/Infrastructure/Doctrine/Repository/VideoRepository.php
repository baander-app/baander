<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Repository;

use App\Catalog\Domain\Model\Video;
use App\Catalog\Domain\Model\VideoState;
use App\Catalog\Domain\ReadModel\VideoPlacementPath;
use App\Catalog\Domain\Repository\VideoRepositoryInterface;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieVideoEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\VideoPlacementEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\VideoEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Shared\Infrastructure\Doctrine\Repository\RefreshedEntityTrait;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Pure domain repository for videos.
 */
final class VideoRepository implements VideoRepositoryInterface
{
    use RefreshedEntityTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function save(Video $video): void
    {
        $entity = $this->findEntityOrCreate($video);
        $this->syncToEntity($video, $entity);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function findByUuid(Uuid $uuid): ?Video
    {
        $entity = $this->entityManager
            ->getRepository(VideoEntity::class)
            ->find($uuid);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findFreshByUuid(Uuid $uuid): ?Video
    {
        $entity = $this->findRefreshedEntity($this->entityManager, VideoEntity::class, $uuid);

        return $entity === null ? null : $this->toDomain($entity);
    }

    public function findByPublicId(PublicId $publicId): ?Video
    {
        $entity = $this->entityManager
            ->getRepository(VideoEntity::class)
            ->findOneBy(['publicId' => $publicId]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function isVisibleByPublicId(PublicId $publicId, LibraryReadScope $scope): bool
    {
        if (!$scope->isUnrestricted() && $scope->getLibraryIds() === []) {
            return false;
        }

        $sql = 'SELECT 1 FROM videos v WHERE v.public_id = :public_id';
        $parameters = ['public_id' => $publicId->toString()];
        $types = [];
        if (!$scope->isUnrestricted()) {
            $sql .= <<<'SQL'
                 AND (
                    EXISTS (
                        SELECT 1 FROM movie_video mv JOIN movies m ON m.id = mv.movie_id
                        WHERE mv.video_id = v.id AND m.library_id IN (:library_ids)
                    ) OR EXISTS (
                        SELECT 1 FROM video_placements p
                        WHERE p.video_id = v.id AND p.library_id IN (:library_ids)
                          AND (p.movie_id IS NOT NULL OR p.extra_kind IS NOT NULL
                            OR EXISTS (SELECT 1 FROM video_placement_episode pe WHERE pe.placement_id = p.id))
                    )
                )
                SQL;
            $parameters['library_ids'] = $scope->getLibraryIds();
            $types['library_ids'] = ArrayParameterType::STRING;
        }

        return $this->entityManager->getConnection()->fetchOne($sql . ' LIMIT 1', $parameters, $types) !== false;
    }

    public function getLibraryIdsByPublicId(PublicId $publicId, LibraryReadScope $scope): array
    {
        if (!$scope->isUnrestricted() && $scope->getLibraryIds() === []) {
            return [];
        }

        // DQL cannot union the feature junction and the placement's library ownership.
        $sql = <<<'SQL'
            SELECT DISTINCT owned.library_id FROM (
                SELECT m.library_id, v.public_id
                FROM videos v JOIN movie_video mv ON mv.video_id = v.id JOIN movies m ON m.id = mv.movie_id
                UNION
                SELECT p.library_id, v.public_id
                FROM videos v JOIN video_placements p ON p.video_id = v.id
                WHERE p.movie_id IS NOT NULL OR p.extra_kind IS NOT NULL
                    OR EXISTS (SELECT 1 FROM video_placement_episode pe WHERE pe.placement_id = p.id)
            ) owned WHERE owned.public_id = :public_id
            SQL;
        $parameters = ['public_id' => $publicId->toString()];
        $types = [];
        if (!$scope->isUnrestricted()) {
            $sql .= ' AND owned.library_id IN (:library_ids)';
            $parameters['library_ids'] = $scope->getLibraryIds();
            $types['library_ids'] = ArrayParameterType::STRING;
        }

        return array_map(
            Uuid::fromString(...),
            $this->entityManager->getConnection()->fetchFirstColumn($sql, $parameters, $types),
        );
    }

    public function placementPaths(array $videoIds, LibraryReadScope $scope): array
    {
        if ($videoIds === [] || (!$scope->isUnrestricted() && $scope->getLibraryIds() === [])) {
            return [];
        }
        $query = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(p.video) AS video_id', 'IDENTITY(p.library) AS library_id', 'library.path AS root', 'p.relativePath AS path', 'video.hash AS hash')
            ->from(VideoPlacementEntity::class, 'p')
            ->join('p.library', 'library')
            ->join('p.video', 'video')
            ->where('p.video IN (:videos)')
            ->andWhere('p.movie IS NOT NULL OR p.extraKind IS NOT NULL OR p.episodes IS NOT EMPTY')
            ->setParameter('videos', array_map(static fn (Uuid $id): string => $id->toString(), $videoIds))
            ->orderBy('p.id', 'ASC');
        if (!$scope->isUnrestricted()) {
            $query->andWhere('p.library IN (:libraries)')->setParameter('libraries', $scope->getLibraryIds());
        }
        $paths = [];
        $roots = [];
        foreach ($query->getQuery()->getArrayResult() as $row) {
            $libraryId = (string) $row['library_id'];
            if (!isset($roots[$libraryId])) {
                clearstatcache(true, $row['root']);
                $roots[$libraryId] = realpath($row['root']) ?: $row['root'];
            }
            $paths[] = new VideoPlacementPath(
                Uuid::fromString((string) $row['video_id']),
                Uuid::fromString((string) $row['library_id']),
                rtrim($roots[$libraryId], '/') . '/' . $row['path'],
                $row['hash'],
            );
        }

        return $paths;
    }

    public function movieVideoIdsForDelete(Uuid $movieId): array
    {
        if (!$this->entityManager->getConnection()->isTransactionActive()) {
            throw new \LogicException('Collecting a movie deletion requires a transaction.');
        }
        if ($this->entityManager->find(MovieEntity::class, $movieId, LockMode::PESSIMISTIC_WRITE) === null) {
            return [];
        }

        // DQL has no UNION for a single snapshot of feature and extra links.
        $ids = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT video_id FROM movie_video WHERE movie_id = :movie UNION SELECT video_id FROM video_placements WHERE movie_id = :movie',
            ['movie' => $movieId->toString()],
        );

        return array_map(Uuid::fromString(...), $ids);
    }

    public function findByHash(string $hash): ?Video
    {
        $entity = $this->entityManager
            ->getRepository(VideoEntity::class)
            ->findOneBy(['hash' => $hash]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    /**
     * @return Video[]
     */
    public function findByMovie(Uuid $movieId): array
    {
        $movieVideoEntities = $this->entityManager
            ->getRepository(MovieVideoEntity::class)
            ->findBy(
                ['movie' => $movieId],
                ['sortOrder' => 'ASC'],
            );

        return array_map(
            fn(MovieVideoEntity $mve) => $this->toDomain($mve->getVideo()),
            $movieVideoEntities,
        );
    }

    public function count(): int
    {
        return (int) $this->entityManager
            ->getRepository(VideoEntity::class)
            ->count([]);
    }

    public function deleteUnlinked(array $videoIds): int
    {
        if ($videoIds === []) {
            return 0;
        }

        return (int) $this->entityManager->getConnection()->executeStatement(
            'DELETE FROM videos v WHERE v.id IN (:ids) AND NOT EXISTS (SELECT 1 FROM movie_video mv WHERE mv.video_id = v.id) AND NOT EXISTS (SELECT 1 FROM video_placements p WHERE p.video_id = v.id)',
            ['ids' => array_map(static fn(Uuid $id): string => $id->toString(), $videoIds)],
            ['ids' => ArrayParameterType::STRING],
        );
    }

    // --- Internal ---

    private function findEntityOrCreate(Video $video): VideoEntity
    {
        $existing = $this->entityManager
            ->getRepository(VideoEntity::class)
            ->find($video->getId());

        if ($existing !== null) {
            return $existing;
        }

        return new VideoEntity(
            $video->getPublicId(),
            $video->getPath(),
            $video->getHash(),
            id: $video->getId(),
        );
    }

    private function toDomain(VideoEntity $entity): Video
    {
        return Video::reconstitute(new VideoState(
            id: $entity->getId(),
            publicId: $entity->getPublicId(),
            path: $entity->getPath(),
            hash: $entity->getHash(),
            duration: $entity->getDuration(),
            height: $entity->getHeight(),
            width: $entity->getWidth(),
            videoBitrate: $entity->getVideoBitrate(),
            framerate: $entity->getFramerate(),
            probe: $entity->getProbe(),
            createdAt: $entity->getCreatedAt(),
            updatedAt: $entity->getUpdatedAt(),
        ));
    }

    private function syncToEntity(Video $video, VideoEntity $entity): void
    {
        $entity->setPath($video->getPath());
        $entity->setHash($video->getHash());
        $entity->setDuration($video->getDuration());
        $entity->setHeight($video->getHeight());
        $entity->setWidth($video->getWidth());
        $entity->setVideoBitrate($video->getVideoBitrate());
        $entity->setFramerate($video->getFramerate());
        $entity->setProbe($video->getProbe());
    }
}
