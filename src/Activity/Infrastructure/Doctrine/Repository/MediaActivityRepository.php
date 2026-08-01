<?php

declare(strict_types=1);

namespace App\Activity\Infrastructure\Doctrine\Repository;

use App\Activity\Domain\Model\MediaActivity;
use App\Activity\Domain\Repository\MediaActivityRepositoryInterface;
use App\Activity\Infrastructure\Doctrine\Entity\MediaActivityEntity;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\SongEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Pure domain repository for media activities.
 */
final class MediaActivityRepository implements MediaActivityRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(MediaActivity $activity): void
    {
        $entity = $this->findEntityOrCreate($activity);
        $this->syncToEntity($activity, $entity);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function findByUuid(Uuid $uuid): ?MediaActivity
    {
        $entity = $this->entityManager
            ->getRepository(MediaActivityEntity::class)
            ->find($uuid);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findByPublicId(PublicId $publicId): ?MediaActivity
    {
        $entity = $this->entityManager
            ->getRepository(MediaActivityEntity::class)
            ->findOneBy(['publicId' => $publicId]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    /**
     * @return MediaActivity[]
     */
    public function findByUser(Uuid $userId, int $limit = 50): array
    {
        $entities = $this->entityManager
            ->getRepository(MediaActivityEntity::class)
            ->createQueryBuilder('a')
            ->where('a.user = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('a.lastPlayedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_map(fn (MediaActivityEntity $entity) => $this->toDomain($entity), $entities);
    }

    public function findForSong(Uuid $userId, Uuid $songId): ?MediaActivity
    {
        $entity = $this->entityManager
            ->getRepository(MediaActivityEntity::class)
            ->findOneBy(['user' => $userId, 'song' => $songId]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findForMovie(Uuid $userId, Uuid $movieId): ?MediaActivity
    {
        $entity = $this->entityManager
            ->getRepository(MediaActivityEntity::class)
            ->findOneBy(['user' => $userId, 'movie' => $movieId]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    /**
     * @return MediaActivity[]
     */
    public function findLoved(Uuid $userId): array
    {
        $entities = $this->entityManager
            ->getRepository(MediaActivityEntity::class)
            ->createQueryBuilder('a')
            ->where('a.user = :userId')
            ->andWhere('a.love = true')
            ->setParameter('userId', $userId)
            ->orderBy('a.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();

        return array_map(fn (MediaActivityEntity $entity) => $this->toDomain($entity), $entities);
    }

    /**
     * @return MediaActivity[]
     */
    public function getRecentlyPlayed(Uuid $userId, int $limit = 20): array
    {
        $entities = $this->entityManager
            ->getRepository(MediaActivityEntity::class)
            ->createQueryBuilder('a')
            ->where('a.user = :userId')
            ->andWhere('a.lastPlayedAt IS NOT NULL')
            ->setParameter('userId', $userId)
            ->orderBy('a.lastPlayedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_map(fn (MediaActivityEntity $entity) => $this->toDomain($entity), $entities);
    }

    public function getAllListeningHistories(): array
    {
        $sql = <<<SQL
            SELECT
                u.id AS user_id,
                ma.song_id,
                COALESCE(ma.play_count, 0) AS play_count
            FROM media_activities ma
            INNER JOIN users u ON u.id = ma.user_id
            WHERE ma.song_id IS NOT NULL OR ma.movie_id IS NOT NULL
              AND ma.play_count > 0
            ORDER BY u.id, ma.play_count DESC
        SQL;

        $stmt = $this->entityManager->getConnection()->prepare($sql);
        $result = $stmt->executeQuery();

        $histories = [];
        while ($row = $result->fetchAssociative()) {
            $userId = $row['user_id'];
            $songId = $row['song_id'];
            $playCount = (int) $row['play_count'];

            if (!isset($histories[$userId])) {
                $histories[$userId] = [];
            }
            $histories[$userId][$songId] = $playCount;
        }

        return $histories;
    }

    // --- Internal ---

    private function toDomain(MediaActivityEntity $entity): MediaActivity
    {
        return MediaActivity::reconstitute(
            $entity->getId(),
            $entity->getPublicId(),
            $entity->getUser()->getId(),
            $entity->getActivityType(),
            $entity->getSong()?->getId(),
            $entity->getAlbum()?->getId(),
            $entity->getArtist()?->getId(),
            $entity->getMovie()?->getId(),
            $entity->getPlayCount() ?? 0,
            $entity->isLove(),
            $entity->getLastPlayedAt(),
            $entity->getLastPlatform(),
            $entity->getLastPlayer(),
            $entity->getCreatedAt(),
            $entity->getUpdatedAt(),
        );
    }

    private function findEntityOrCreate(MediaActivity $activity): MediaActivityEntity
    {
        $existing = $this->entityManager
            ->getRepository(MediaActivityEntity::class)
            ->find($activity->getId());

        if ($existing !== null) {
            return $existing;
        }

        $userEntity = $this->entityManager
            ->getRepository(UserEntity::class)
            ->find($activity->getUserId());

        if ($userEntity === null) {
            throw new \RuntimeException(sprintf('User with id "%s" not found.', $activity->getUserId()->toString()));
        }

        $entity = new MediaActivityEntity(
            $activity->getPublicId(),
            $userEntity,
            $activity->getActivityType(),
            id: $activity->getId(),
        );

        // Set optional FKs
        $this->setOptionalRelation($entity, 'setSong', SongEntity::class, $activity->getSongId());
        $this->setOptionalRelation($entity, 'setAlbum', AlbumEntity::class, $activity->getAlbumId());
        $this->setOptionalRelation($entity, 'setArtist', ArtistEntity::class, $activity->getArtistId());
        $this->setOptionalRelation($entity, 'setMovie', MovieEntity::class, $activity->getMovieId());

        return $entity;
    }

    private function syncToEntity(MediaActivity $activity, MediaActivityEntity $entity): void
    {
        $entity->setActivityType($activity->getActivityType());
        $entity->setLove($activity->isLove());

        // Sync play count by incrementing the entity to match
        if ($activity->getPlayCount() > ($entity->getPlayCount() ?? 0)) {
            $entity->incrementPlayCount($activity->getPlayCount() - ($entity->getPlayCount() ?? 0));
        }

        $entity->setLastPlatform($activity->getLastPlatform());
        $entity->setLastPlayer($activity->getLastPlayer());

        // Update optional FKs
        $this->setOptionalRelation($entity, 'setSong', SongEntity::class, $activity->getSongId());
        $this->setOptionalRelation($entity, 'setAlbum', AlbumEntity::class, $activity->getAlbumId());
        $this->setOptionalRelation($entity, 'setArtist', ArtistEntity::class, $activity->getArtistId());
        $this->setOptionalRelation($entity, 'setMovie', MovieEntity::class, $activity->getMovieId());
    }

    /**
     * Resolve an optional FK relation and call the entity setter.
     */
    private function setOptionalRelation(
        MediaActivityEntity $entity,
        string $setter,
        string $entityClass,
        ?Uuid $id,
    ): void {
        if ($id === null) {
            $entity->$setter(null);

            return;
        }

        $related = $this->entityManager
            ->getRepository($entityClass)
            ->find($id);

        $entity->$setter($related);
    }
}
