<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Doctrine\Repository;

use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistEntity;
use App\Media\Domain\Model\Image;
use App\Media\Domain\Model\ImageState;
use App\Media\Domain\ReadModel\ImageReadView;
use App\Media\Domain\Repository\ImageRepositoryInterface;
use App\Media\Infrastructure\Doctrine\Entity\ImageEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\MediaReadScope;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use LogicException;

final class ImageRepository implements ImageRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function findVisibleByPublicId(PublicId $publicId, MediaReadScope $scope): ?ImageReadView
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select(
                'i.id', 'i.publicId', 'i.path', 'i.extension', 'i.mimeType', 'i.blurhash',
                'i.size', 'i.width', 'i.height', 'i.imageableType', 'i.createdAt', 'i.updatedAt',
                'IDENTITY(i.album) AS albumId', 'IDENTITY(i.artist) AS artistId',
                'IDENTITY(i.playlist) AS playlistId',
            )
            ->from(ImageEntity::class, 'i')
            ->where('i.publicId = :publicId')
            ->setParameter('publicId', $publicId);
        $this->applyReadScope($qb, $scope);
        if ($scope->isUnrestricted()) {
            $qb->addSelect('1 AS albumVisible', '1 AS artistVisible', '1 AS playlistVisible');
        } else {
            if ($scope->getLibraries()->getLibraryIds() === []) {
                $qb->addSelect('0 AS albumVisible', '0 AS artistVisible');
            } else {
                $album = $this->albumVisibility('owner_album', 'owner_album.id = IDENTITY(i.album)');
                $artist = $this->artistVisibility('owner_artist', 'owner_artist.id = IDENTITY(i.artist)');
                $qb->addSelect(
                    'CASE WHEN ' . $album . ' THEN 1 ELSE 0 END AS albumVisible',
                    'CASE WHEN ' . $artist . ' THEN 1 ELSE 0 END AS artistVisible',
                );
            }
            if ($scope->getActorId() === null) {
                $qb->addSelect('0 AS playlistVisible');
            } else {
                $qb->addSelect(
                    'CASE WHEN ' . $this->playlistVisibility('owner_playlist') . ' THEN 1 ELSE 0 END AS playlistVisible',
                );
            }
        }

        /** @var array{
         *     id: Uuid, publicId: PublicId, path: string, extension: string, mimeType: string,
         *     blurhash: ?string, size: int, width: int, height: int, imageableType: string,
         *     createdAt: DateTimeImmutable, updatedAt: DateTimeImmutable,
         *     albumId: Uuid|string|null, artistId: Uuid|string|null, playlistId: Uuid|string|null,
         *     albumVisible: int, artistVisible: int, playlistVisible: int
         * }|null $row
         */
        $row = $qb->getQuery()->getOneOrNullResult();
        if ($row === null) {
            return null;
        }

        return new ImageReadView(
            $row['id'],
            $row['publicId'],
            $row['path'],
            $row['extension'],
            $row['mimeType'],
            $row['blurhash'],
            $row['size'],
            $row['width'],
            $row['height'],
            $row['imageableType'],
            $this->visibleOwnerId($row['albumId'], (bool) $row['albumVisible']),
            $this->visibleOwnerId($row['artistId'], (bool) $row['artistVisible']),
            $this->visibleOwnerId($row['playlistId'], (bool) $row['playlistVisible']),
            $row['createdAt'],
            $row['updatedAt'],
        );
    }

    public function saveVisibleBlurhash(Uuid $imageId, string $blurhash, MediaReadScope $scope): bool
    {
        if ($this->entityManager->getUnitOfWork()->tryGetById(['id' => $imageId], ImageEntity::class) !== false) {
            throw new LogicException('Flush and clear the target image before an atomic blurhash update.');
        }

        $qb = $this->entityManager->createQueryBuilder()
            ->update(ImageEntity::class, 'i')
            ->set('i.blurhash', ':blurhash')
            ->set('i.updatedAt', ':updatedAt')
            ->where('i.id = :id')
            ->setParameter('id', $imageId)
            ->setParameter('blurhash', $blurhash)
            ->setParameter('updatedAt', new DateTimeImmutable());
        $this->applyReadScope($qb, $scope);

        return $qb->getQuery()->execute() === 1;
    }

    private function applyReadScope(QueryBuilder $qb, MediaReadScope $scope): void
    {
        if ($scope->getActorId() === null) {
            $qb->andWhere('1 = 0');
            return;
        }
        if ($scope->isUnrestricted()) {
            return;
        }

        $predicates = [$this->playlistVisibility('visible_playlist')];
        $qb->setParameter('image_actor', $scope->getActorId());
        if ($scope->getLibraries()->getLibraryIds() !== []) {
            $predicates[] = $this->albumVisibility(
                'visible_album',
                'visible_album.id = IDENTITY(i.album) OR visible_album.coverImage = i.id',
            );
            $predicates[] = $this->artistVisibility(
                'visible_artist',
                'visible_artist.id = IDENTITY(i.artist) OR visible_artist.coverImage = i.id',
            );
            $qb->setParameter('image_libraries', $scope->getLibraries()->getLibraryIds(), ArrayParameterType::STRING);
        }
        $qb->andWhere('(' . implode(' OR ', $predicates) . ')');
    }

    private function albumVisibility(string $alias, string $association): string
    {
        return <<<DQL
            EXISTS (
                SELECT $alias.id
                FROM App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity $alias
                WHERE ($association) AND IDENTITY($alias.library) IN (:image_libraries)
            )
            DQL;
    }

    private function artistVisibility(string $alias, string $association): string
    {
        return <<<DQL
            EXISTS (
                SELECT $alias.id
                FROM App\Catalog\Infrastructure\Doctrine\Entity\ArtistEntity $alias
                WHERE ($association) AND (
                    EXISTS (
                        SELECT {$alias}_aa.id
                        FROM App\Catalog\Infrastructure\Doctrine\Entity\ArtistAlbumEntity {$alias}_aa
                        JOIN {$alias}_aa.album {$alias}_album
                        WHERE {$alias}_aa.artist = $alias.id
                            AND IDENTITY({$alias}_album.library) IN (:image_libraries)
                    ) OR EXISTS (
                        SELECT {$alias}_as.id
                        FROM App\Catalog\Infrastructure\Doctrine\Entity\ArtistSongEntity {$alias}_as
                        JOIN {$alias}_as.song {$alias}_song
                        JOIN {$alias}_song.album {$alias}_song_album
                        WHERE {$alias}_as.artist = $alias.id
                            AND IDENTITY({$alias}_song_album.library) IN (:image_libraries)
                    )
                )
            )
            DQL;
    }

    private function playlistVisibility(string $alias): string
    {
        return <<<DQL
            EXISTS (
                SELECT $alias.id
                FROM App\Playlist\Infrastructure\Doctrine\Entity\PlaylistEntity $alias
                WHERE $alias.id = IDENTITY(i.playlist) AND IDENTITY($alias.user) = :image_actor
            )
            DQL;
    }

    private function visibleOwnerId(Uuid|string|null $ownerId, bool $visible): ?Uuid
    {
        if (!$visible || $ownerId === null) {
            return null;
        }

        return $ownerId instanceof Uuid ? $ownerId : Uuid::fromString($ownerId);
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
