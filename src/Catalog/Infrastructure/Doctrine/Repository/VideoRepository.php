<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Repository;

use App\Catalog\Domain\Model\Video;
use App\Catalog\Domain\Model\VideoState;
use App\Catalog\Domain\Repository\VideoRepositoryInterface;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieVideoEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\VideoEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Pure domain repository for videos.
 */
final class VideoRepository implements VideoRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

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

    public function findByPublicId(PublicId $publicId): ?Video
    {
        $entity = $this->entityManager
            ->getRepository(VideoEntity::class)
            ->findOneBy(['publicId' => $publicId]);

        return $entity !== null ? $this->toDomain($entity) : null;
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
            fn (MovieVideoEntity $mve) => $this->toDomain($mve->getVideo()),
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
            'DELETE FROM videos v WHERE v.id IN (:ids) AND NOT EXISTS (SELECT 1 FROM movie_video mv WHERE mv.video_id = v.id)',
            ['ids' => array_map(static fn (Uuid $id): string => $id->toString(), $videoIds)],
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
