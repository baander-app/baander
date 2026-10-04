<?php

declare(strict_types=1);

namespace App\Playlist\Infrastructure\Doctrine\Repository;

use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\SongEntity;
use App\Playlist\Domain\Model\Playlist;
use App\Playlist\Domain\Model\PlaylistSong;
use App\Playlist\Domain\ReadModel\PlaylistReadView;
use App\Playlist\Domain\Repository\PlaylistRepositoryInterface;
use App\Playlist\Infrastructure\Doctrine\Entity\PlaylistEntity;
use App\Playlist\Infrastructure\Doctrine\Entity\PlaylistSongEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

final class PlaylistRepository implements PlaylistRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(Playlist $playlist): void
    {
        $entity = $this->findEntityOrCreate($playlist);
        $this->syncToEntity($playlist, $entity);
        $this->syncSongsToEntity($playlist, $entity);

        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function findByUuid(Uuid $uuid): ?Playlist
    {
        $entity = $this->entityManager
            ->getRepository(PlaylistEntity::class)
            ->find($uuid);

        return $entity !== null ? $this->toDomain($entity, $this->loadSongs($entity)) : null;
    }

    public function findByPublicId(PublicId $publicId): ?Playlist
    {
        $entity = $this->entityManager
            ->getRepository(PlaylistEntity::class)
            ->findOneBy(['publicId' => $publicId]);

        return $entity !== null ? $this->toDomain($entity, $this->loadSongs($entity)) : null;
    }

    public function findByUser(Uuid $userId): array
    {
        // List projection — metadata only (no songs), since no save follows a list.
        $entities = $this->entityManager
            ->getRepository(PlaylistEntity::class)
            ->createQueryBuilder('p')
            ->innerJoin('p.user', 'u')
            ->where('u.id = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getResult();

        return array_map(fn(PlaylistEntity $entity) => $this->toDomain($entity), $entities);
    }

    public function findReadByUser(Uuid $ownerId, LibraryReadScope $scope): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select(
                'p.id, p.publicId, IDENTITY(p.user) AS userId',
                'p.name, p.description, p.isPublic, p.isCollaborative, p.isSmart',
                'p.smartRules, p.createdAt, p.updatedAt',
                'COUNT(read_song.id) AS songCount',
            )
            ->from(PlaylistEntity::class, 'p')
            ->leftJoin(PlaylistSongEntity::class, 'membership', 'WITH', 'membership.playlist = p.id')
            ->where('IDENTITY(p.user) = :ownerId')
            ->setParameter('ownerId', $ownerId, 'uuid')
            ->groupBy('p.id')
            ->orderBy('p.name', 'ASC')
            ->addOrderBy('p.id', 'ASC');

        if ($scope->isUnrestricted()) {
            $query->leftJoin('membership.song', 'read_song');
        } elseif ($scope->getLibraryIds() === []) {
            $query->leftJoin('membership.song', 'read_song', 'WITH', '1 = 0');
        } else {
            $visibleAlbum = <<<'DQL'
                read_song.album IN (
                    SELECT scoped_album.id
                    FROM App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity scoped_album
                    WHERE IDENTITY(scoped_album.library) IN (:libraryIds)
                )
                DQL;
            $query->leftJoin('membership.song', 'read_song', 'WITH', $visibleAlbum)
                ->setParameter('libraryIds', $scope->getLibraryIds(), ArrayParameterType::STRING);
        }

        /**
         * @var list<array{
         *     id: Uuid, publicId: PublicId, userId: string, name: string,
         *     description: ?string, isPublic: bool, isCollaborative: bool, isSmart: bool,
         *     smartRules: array<array-key, mixed>, createdAt: \DateTimeImmutable,
         *     updatedAt: \DateTimeImmutable, songCount: int|string
         * }> $rows
         */
        $rows = $query->getQuery()->getArrayResult();
        return array_map(static fn (array $row): PlaylistReadView => new PlaylistReadView(
            id: $row['id'],
            publicId: $row['publicId'],
            userId: Uuid::fromString($row['userId']),
            name: $row['name'],
            description: $row['description'],
            isPublic: $row['isPublic'],
            isCollaborative: $row['isCollaborative'],
            isSmart: $row['isSmart'],
            smartRules: $row['smartRules'],
            createdAt: $row['createdAt'],
            updatedAt: $row['updatedAt'],
            songCount: (int) $row['songCount'],
        ), $rows);
    }

    public function findWithSongs(Uuid $id): ?Playlist
    {
        $entity = $this->entityManager
            ->getRepository(PlaylistEntity::class)
            ->find($id);

        return $entity !== null ? $this->toDomain($entity, $this->loadSongs($entity)) : null;
    }

    public function findPlaylistNamesContainingSong(Uuid $songId): array
    {
        $playlistSongEntities = $this->entityManager
            ->getRepository(PlaylistSongEntity::class)
            ->createQueryBuilder('ps')
            ->innerJoin('ps.playlist', 'p')
            ->innerJoin('ps.song', 's')
            ->where('s.id = :songId')
            ->setParameter('songId', $songId)
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($playlistSongEntities as $psEntity) {
            $playlist = $psEntity->getPlaylist();
            $result[] = [
                'uuid' => $playlist->getId()->toString(),
                'name' => $playlist->getName(),
            ];
        }

        return $result;
    }

    public function delete(Playlist $playlist): void
    {
        $entity = $this->entityManager
            ->getRepository(PlaylistEntity::class)
            ->find($playlist->getId());

        if ($entity !== null) {
            // Remove playlist_song entries first
            $songEntities = $this->entityManager
                ->getRepository(PlaylistSongEntity::class)
                ->createQueryBuilder('ps')
                ->innerJoin('ps.playlist', 'p')
                ->where('p.id = :playlistId')
                ->setParameter('playlistId', $playlist->getId())
                ->getQuery()
                ->getResult();

            foreach ($songEntities as $songEntity) {
                $this->entityManager->remove($songEntity);
            }

            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        }
    }

    // --- Internal ---

    private function findEntityOrCreate(Playlist $playlist): PlaylistEntity
    {
        $existing = $this->entityManager
            ->getRepository(PlaylistEntity::class)
            ->find($playlist->getId());

        if ($existing !== null) {
            return $existing;
        }

        $userEntity = $this->entityManager
            ->getRepository(UserEntity::class)
            ->find($playlist->getUserId());

        if ($userEntity === null) {
            throw new \RuntimeException(
                sprintf('User entity not found for UUID %s.', $playlist->getUserId()->toString()),
            );
        }

        return new PlaylistEntity(
            $playlist->getPublicId(),
            $userEntity,
            $playlist->getName(),
            id: $playlist->getId(),
        );
    }

    /**
     * @param PlaylistSong[] $songs
     */
    private function toDomain(PlaylistEntity $entity, array $songs = []): Playlist
    {
        return Playlist::reconstitute(
            $entity->getId(),
            $entity->getPublicId(),
            $entity->getUser()->getId(),
            $entity->getName(),
            $entity->getDescription(),
            $entity->isPublic(),
            $entity->isCollaborative(),
            $entity->isSmart(),
            $entity->getSmartRules(),
            $entity->getCreatedAt(),
            $entity->getUpdatedAt(),
            $songs,
        );
    }

    /**
     * Load the playlist's songs ordered by position.
     *
     * @return list<PlaylistSong>
     */
    private function loadSongs(PlaylistEntity $entity): array
    {
        $songEntities = $this->entityManager
            ->getRepository(PlaylistSongEntity::class)
            ->createQueryBuilder('ps')
            ->innerJoin('ps.song', 's')
            ->where('ps.playlist = :playlist')
            ->setParameter('playlist', $entity)
            ->orderBy('ps.position', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(
            static fn(PlaylistSongEntity $ps): PlaylistSong => new PlaylistSong($ps->getSong()->getId(), $ps->getPosition()),
            $songEntities,
        );
    }

    private function syncToEntity(Playlist $playlist, PlaylistEntity $entity): void
    {
        $entity->setName($playlist->getName());
        $entity->setDescription($playlist->getDescription());
        $entity->setPublic($playlist->isPublic());
        $entity->setCollaborative($playlist->isCollaborative());
        $entity->setSmart($playlist->isSmart());
        $entity->setSmartRules($playlist->getSmartRules());
    }

    /**
     * Reconcile persisted playlist_song rows with the aggregate's song collection.
     *
     * The aggregate is the source of truth (callers must load it with its songs
     * before mutating + saving). Inserts new members, updates positions of retained
     * songs, and removes dropped songs.
     */
    private function syncSongsToEntity(Playlist $playlist, PlaylistEntity $entity): void
    {
        /** @var array<string, int> $desired songId => position */
        $desired = [];
        foreach ($playlist->getSongs() as $song) {
            $desired[$song->getSongId()->toString()] = $song->getPosition();
        }

        $existing = $this->entityManager
            ->getRepository(PlaylistSongEntity::class)
            ->createQueryBuilder('ps')
            ->innerJoin('ps.playlist', 'p')
            ->where('p.id = :playlistId')
            ->setParameter('playlistId', $playlist->getId())
            ->getQuery()
            ->getResult();

        /** @var array<string, PlaylistSongEntity> $existingBySong */
        $existingBySong = [];
        foreach ($existing as $psEntity) {
            $existingBySong[$psEntity->getSong()->getId()->toString()] = $psEntity;
        }

        // Remove dropped songs; update positions of retained songs.
        foreach ($existingBySong as $songIdStr => $psEntity) {
            if (!array_key_exists($songIdStr, $desired)) {
                $this->entityManager->remove($psEntity);
            } elseif ($psEntity->getPosition() !== $desired[$songIdStr]) {
                $psEntity->setPosition($desired[$songIdStr]);
            }
        }

        // Insert newly added songs (a reference is enough to set the FK).
        foreach (array_diff_key($desired, $existingBySong) as $songIdStr => $position) {
            $songEntity = $this->entityManager->getReference(SongEntity::class, Uuid::fromString($songIdStr));
            $this->entityManager->persist(new PlaylistSongEntity($entity, $songEntity, $position));
        }
    }
}
