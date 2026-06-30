<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Doctrine\Repository;

use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistEntity;
use App\Media\Domain\Model\Image;
use App\Media\Domain\Model\ImageState;
use App\Media\Domain\Repository\ImageRepositoryInterface;
use App\Media\Infrastructure\Doctrine\Entity\ImageEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;

final class ImageRepository implements ImageRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(Image $image): void
    {
        $entity = $this->findEntityOrCreate($image);
        $this->syncToEntity($image, $entity);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function findByUuid(Uuid $uuid): ?Image
    {
        $entity = $this->entityManager
            ->getRepository(ImageEntity::class)
            ->find($uuid);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findByUuids(array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }

        $entities = $this->entityManager
            ->getRepository(ImageEntity::class)
            ->createQueryBuilder('i')
            ->where('i.id IN (:ids)')
            ->setParameter('ids', $uuids)
            ->getQuery()
            ->getResult();

        $images = [];
        foreach ($entities as $entity) {
            $images[$entity->getId()->toString()] = $this->toDomain($entity);
        }

        return $images;
    }

    public function findByPublicId(PublicId $publicId): ?Image
    {
        $entity = $this->entityManager
            ->getRepository(ImageEntity::class)
            ->findOneBy(['publicId' => $publicId]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findByOwner(string $imageableType, Uuid $ownerId): array
    {
        $qb = $this->entityManager
            ->getRepository(ImageEntity::class)
            ->createQueryBuilder('i');

        $qb->where('i.imageableType = :type')
            ->setParameter('type', $imageableType);

        $this->addOwnerCondition($qb, $imageableType, $ownerId);
        $qb->orderBy('i.createdAt', 'ASC');

        $entities = $qb->getQuery()->getResult();

        return array_map(fn (ImageEntity $entity) => $this->toDomain($entity), $entities);
    }

    public function findPrimaryForOwner(string $imageableType, Uuid $ownerId): ?Image
    {
        $qb = $this->entityManager
            ->getRepository(ImageEntity::class)
            ->createQueryBuilder('i');

        $qb->where('i.imageableType = :type')
            ->setParameter('type', $imageableType);

        $this->addOwnerCondition($qb, $imageableType, $ownerId);
        $qb->setMaxResults(1);

        $entity = $qb->getQuery()->getOneOrNullResult();

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findAllAfter(?Uuid $cursor, int $limit): array
    {
        $qb = $this->entityManager
            ->getRepository(ImageEntity::class)
            ->createQueryBuilder('i')
            ->orderBy('i.id', 'ASC')
            ->setMaxResults($limit);

        if ($cursor !== null) {
            $qb->where('i.id > :cursor')->setParameter('cursor', $cursor);
        }

        $entities = $qb->getQuery()->getResult();

        return array_map(fn (ImageEntity $entity) => $this->toDomain($entity), $entities);
    }

    public function findAll(int $limit, int $offset): array
    {
        $entities = $this->entityManager
            ->getRepository(ImageEntity::class)
            ->createQueryBuilder('i')
            ->orderBy('i.createdAt', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_map(fn (ImageEntity $entity) => $this->toDomain($entity), $entities);
    }

    public function countAll(): int
    {
        return (int) $this->entityManager
            ->getRepository(ImageEntity::class)
            ->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function delete(Image $image): void
    {
        $entity = $this->entityManager
            ->getRepository(ImageEntity::class)
            ->find($image->getId());

        if ($entity !== null) {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        }
    }

    // --- Internal ---

    private function findEntityOrCreate(Image $image): ImageEntity
    {
        $existing = $this->entityManager
            ->getRepository(ImageEntity::class)
            ->find($image->getId());

        if ($existing !== null) {
            return $existing;
        }

        $entity = new ImageEntity(
            $image->getPath(),
            $image->getExtension(),
            $image->getMimeType(),
            $image->getPublicId(),
            $image->getSize(),
            $image->getWidth(),
            $image->getHeight(),
            $image->getImageableType(),
            id: $image->getId(),
        );

        // Set owner relationships on new entity
        if ($image->getAlbumId() !== null) {
            $albumEntity = $this->entityManager->getRepository(AlbumEntity::class)->find($image->getAlbumId());
            if ($albumEntity !== null) {
                $entity->setAlbum($albumEntity);
            }
        }

        return $entity;
    }

    private function toDomain(ImageEntity $entity): Image
    {
        return Image::reconstitute(new ImageState(
            id: $entity->getId(),
            publicId: $entity->getPublicId(),
            path: $entity->getPath(),
            extension: $entity->getExtension(),
            mimeType: $entity->getMimeType(),
            blurhash: $entity->getBlurhash(),
            size: $entity->getSize(),
            width: $entity->getWidth(),
            height: $entity->getHeight(),
            imageableType: $entity->getImageableType(),
            albumId: $entity->getAlbum()?->getId(),
            artistId: $entity->getArtist()?->getId(),
            playlistId: null, // playlist not mapped yet
            createdAt: $entity->getCreatedAt(),
            updatedAt: $entity->getUpdatedAt(),
        ));
    }

    private function syncToEntity(Image $image, ImageEntity $entity): void
    {
        $entity->setPath($image->getPath());
        $entity->setBlurhash($image->getBlurhash());
        $entity->setSize($image->getSize());
        $entity->setWidth($image->getWidth());
        $entity->setHeight($image->getHeight());

        // Sync owner relationships
        if ($image->getAlbumId() !== null) {
            $albumEntity = $this->entityManager->getRepository(AlbumEntity::class)->find($image->getAlbumId());
            $entity->setAlbum($albumEntity);
        } else {
            $entity->setAlbum(null);
        }
    }

    /**
     * @param \Doctrine\ORM\QueryBuilder $qb
     */
    private function addOwnerCondition(\Doctrine\ORM\QueryBuilder $qb, string $imageableType, Uuid $ownerId): void
    {
        match ($imageableType) {
            'album' => $qb->join('i.album', 'a')->andWhere('a.id = :ownerId'),
            'artist' => $qb->join('i.artist', 'ar')->andWhere('ar.id = :ownerId'),
            'playlist' => $qb->join('i.playlist', 'p')->andWhere('p.id = :ownerId'),
            default => $qb->andWhere('1 = 0'),
        };

        $qb->setParameter('ownerId', $ownerId);
    }
}
