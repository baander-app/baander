<?php

declare(strict_types=1);

namespace App\Playlist\Infrastructure\Doctrine\Repository;

use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Catalog\Application\Port\SongLookupInterface;
use App\Playlist\Domain\Model\Playlist;
use App\Playlist\Domain\Model\PlaylistSong;
use App\Playlist\Domain\ReadModel\PlaylistReadView;
use App\Playlist\Domain\Repository\PlaylistRepositoryInterface;
use App\Playlist\Infrastructure\Doctrine\Entity\PlaylistCollaboratorEntity;
use App\Playlist\Infrastructure\Doctrine\Entity\PlaylistEntity;
use App\Playlist\Infrastructure\Doctrine\Entity\PlaylistSongEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

final class PlaylistRepository implements PlaylistRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SongLookupInterface $songs,
    ) {
    }

    public function save(Playlist $playlist): void
    {
        $entity = $this->findEntityOrCreate($playlist);
        $isNew = !$this->entityManager->contains($entity);
        $this->syncToEntity($playlist, $entity);
        $this->syncSongsToEntity($playlist, $entity);
        $this->syncCollaboratorsToEntity($playlist, $entity, $isNew);

        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function findByUuid(Uuid $uuid): ?Playlist
    {
        $entity = $this->entityManager
            ->getRepository(PlaylistEntity::class)
            ->find($uuid);

        return $entity !== null ? $this->toDomain($entity, $this->loadSongs($entity), $this->loadCollaboratorIds($entity)) : null;
    }

    public function findByPublicId(PublicId $publicId): ?Playlist
    {
        $entity = $this->entityManager
            ->getRepository(PlaylistEntity::class)
            ->findOneBy(['publicId' => $publicId]);

        return $entity !== null ? $this->toDomain($entity, $this->loadSongs($entity), $this->loadCollaboratorIds($entity)) : null;
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

        $collaborators = $this->loadCollaboratorIdsByPlaylist($entities);

        return array_map(
            fn(PlaylistEntity $entity) => $this->toDomain($entity, [], $collaborators[$entity->getId()->toString()] ?? []),
            $entities,
        );
    }

    public function findReadByUser(Uuid $actorId, LibraryReadScope $scope): array
    {
        $collaborated = sprintf(
            'SELECT shared.id FROM %s c INNER JOIN c.playlist shared WHERE IDENTITY(c.user) = :actorId',
            PlaylistCollaboratorEntity::class,
        );
        $query = $this->readProjection()
            ->where(sprintf('IDENTITY(p.user) = :actorId OR p.id IN (%s)', $collaborated))
            ->setParameter('actorId', $actorId, 'uuid')
            ->orderBy('p.name', 'ASC')
            ->addOrderBy('p.id', 'ASC');

        return $this->readViews($query, $scope);
    }

    public function findRead(Uuid $playlistId, LibraryReadScope $scope): ?PlaylistReadView
    {
        $query = $this->readProjection()
            ->where('p.id = :playlistId')
            ->setParameter('playlistId', $playlistId, 'uuid');

        return $this->readViews($query, $scope)[0] ?? null;
    }

    /**
     * The list projection: metadata and the owner's public id, no songs, since no save follows a read.
     */
    private function readProjection(): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select(
                'p.id, p.publicId, owner.publicId AS ownerPublicId',
                'p.name, p.description, p.isPublic, p.isCollaborative, p.isSmart',
                'p.smartRules, p.createdAt, p.updatedAt',
            )
            ->from(PlaylistEntity::class, 'p')
            ->innerJoin('p.user', 'owner');
    }

    /**
     * Runs the projection and completes each row with its collaborators' public ids and
     * the number of songs visible under the scope.
     *
     * @return list<PlaylistReadView>
     */
    private function readViews(QueryBuilder $query, LibraryReadScope $scope): array
    {
        if ($scope->isUnrestricted()) {
            $query->addSelect(sprintf(
                '(SELECT COUNT(membership.id) FROM %s membership WHERE membership.playlist = p.id) AS songCount',
                PlaylistSongEntity::class,
            ));
        }

        /**
         * @var list<array{
         *     id: Uuid, publicId: PublicId, ownerPublicId: PublicId, name: string,
         *     description: ?string, isPublic: bool, isCollaborative: bool, isSmart: bool,
         *     smartRules: array<array-key, mixed>, createdAt: \DateTimeImmutable,
         *     updatedAt: \DateTimeImmutable, songCount?: int|string
         * }> $rows
         */
        $rows = $query->getQuery()->getArrayResult();
        if ($rows === []) {
            return [];
        }

        $playlistIds = array_map(static fn (array $row): Uuid => $row['id'], $rows);
        $collaborators = $this->loadCollaboratorPublicIdsByPlaylist($playlistIds);
        if (!$scope->isUnrestricted()) {
            $visibleCounts = $this->countVisibleSongsByPlaylist($playlistIds, $scope);
            foreach ($rows as $index => $row) {
                $rows[$index]['songCount'] = $visibleCounts[$row['id']->toString()] ?? 0;
            }
        }

        return array_map(static fn (array $row): PlaylistReadView => new PlaylistReadView(
            id: $row['id'],
            publicId: $row['publicId'],
            ownerPublicId: $row['ownerPublicId'],
            collaboratorPublicIds: $collaborators[$row['id']->toString()] ?? [],
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

    /**
     * The collaborators' public ids of each playlist, oldest row first.
     *
     * @param list<Uuid> $playlistIds
     * @return array<string, list<PublicId>> playlist UUID => public ids; playlists without one are absent
     */
    private function loadCollaboratorPublicIdsByPlaylist(array $playlistIds): array
    {
        /** @var list<array{playlistId: Uuid, userPublicId: PublicId}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('shared.id AS playlistId', 'collaborator.publicId AS userPublicId')
            ->from(PlaylistCollaboratorEntity::class, 'c')
            ->innerJoin('c.playlist', 'shared')
            ->innerJoin('c.user', 'collaborator')
            ->where('shared.id IN (:playlistIds)')
            ->setParameter('playlistIds', $playlistIds)
            ->orderBy('c.createdAt', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $byPlaylist = [];
        foreach ($rows as $row) {
            $byPlaylist[$row['playlistId']->toString()][] = $row['userPublicId'];
        }

        return $byPlaylist;
    }

    /**
     * Counts each playlist's entries whose songs Catalog reports visible under the scope.
     *
     * @param list<Uuid> $playlistIds
     * @return array<string, int> playlist UUID => visible entry count; playlists without one are absent
     */
    private function countVisibleSongsByPlaylist(array $playlistIds, LibraryReadScope $scope): array
    {
        if ($scope->getLibraryIds() === []) {
            return [];
        }

        /** @var list<array{playlistId: string, songId: Uuid}> $memberships */
        $memberships = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(membership.playlist) AS playlistId', 'membership.songId')
            ->from(PlaylistSongEntity::class, 'membership')
            ->where('membership.playlist IN (:playlistIds)')
            ->setParameter('playlistIds', $playlistIds)
            ->getQuery()
            ->getArrayResult();
        if ($memberships === []) {
            return [];
        }

        $songIds = [];
        foreach ($memberships as $membership) {
            $songIds[$membership['songId']->toString()] = $membership['songId'];
        }
        $visible = [];
        foreach ($this->songs->visibleSongIds(array_values($songIds), $scope) as $songId) {
            $visible[$songId->toString()] = true;
        }

        $counts = [];
        foreach ($memberships as $membership) {
            if (isset($visible[$membership['songId']->toString()])) {
                $counts[$membership['playlistId']] = ($counts[$membership['playlistId']] ?? 0) + 1;
            }
        }

        return $counts;
    }

    public function findWithSongs(Uuid $id): ?Playlist
    {
        $entity = $this->entityManager
            ->getRepository(PlaylistEntity::class)
            ->find($id);

        return $entity !== null ? $this->toDomain($entity, $this->loadSongs($entity), $this->loadCollaboratorIds($entity)) : null;
    }

    public function findPlaylistNamesContainingSong(Uuid $songId): array
    {
        $playlistSongEntities = $this->entityManager
            ->getRepository(PlaylistSongEntity::class)
            ->createQueryBuilder('ps')
            ->innerJoin('ps.playlist', 'p')
            ->where('ps.songId = :songId')
            ->setParameter('songId', $songId, 'uuid')
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
     * @param list<Uuid>     $collaboratorIds
     */
    private function toDomain(PlaylistEntity $entity, array $songs, array $collaboratorIds): Playlist
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
            $collaboratorIds,
        );
    }

    /**
     * The playlist's collaborator user ids, oldest row first.
     *
     * @return list<Uuid>
     */
    private function loadCollaboratorIds(PlaylistEntity $entity): array
    {
        /** @var list<array{userId: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(c.user) AS userId')
            ->from(PlaylistCollaboratorEntity::class, 'c')
            ->where('c.playlist = :playlist')
            ->setParameter('playlist', $entity)
            ->orderBy('c.createdAt', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): Uuid => Uuid::fromString($row['userId']), $rows);
    }

    /**
     * The collaborator user ids of every given playlist in one query, keyed by playlist id,
     * oldest row first within a playlist.
     *
     * @param list<PlaylistEntity> $entities
     *
     * @return array<string, list<Uuid>>
     */
    private function loadCollaboratorIdsByPlaylist(array $entities): array
    {
        if ($entities === []) {
            return [];
        }

        /** @var list<array{playlistId: string, userId: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(c.playlist) AS playlistId', 'IDENTITY(c.user) AS userId')
            ->from(PlaylistCollaboratorEntity::class, 'c')
            ->where('c.playlist IN (:playlists)')
            ->setParameter('playlists', $entities)
            ->orderBy('c.createdAt', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $byPlaylist = [];
        foreach ($rows as $row) {
            $byPlaylist[$row['playlistId']][] = Uuid::fromString($row['userId']);
        }

        return $byPlaylist;
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
            ->where('ps.playlist = :playlist')
            ->setParameter('playlist', $entity)
            ->orderBy('ps.position', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(
            static fn(PlaylistSongEntity $ps): PlaylistSong => new PlaylistSong($ps->getSongId(), $ps->getPosition()),
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
            $existingBySong[$psEntity->getSongId()->toString()] = $psEntity;
        }

        // Remove dropped songs; update positions of retained songs.
        foreach ($existingBySong as $songIdStr => $psEntity) {
            if (!array_key_exists($songIdStr, $desired)) {
                $this->entityManager->remove($psEntity);
            } elseif ($psEntity->getPosition() !== $desired[$songIdStr]) {
                $psEntity->setPosition($desired[$songIdStr]);
            }
        }

        // Insert newly added songs; fk_playlist_song_song_id rejects unknown songs.
        foreach (array_diff_key($desired, $existingBySong) as $songIdStr => $position) {
            $this->entityManager->persist(new PlaylistSongEntity($entity, Uuid::fromString((string) $songIdStr), $position));
        }
    }

    /**
     * Reconcile persisted playlist_collaborators rows with the aggregate's collaborator ids:
     * a user the aggregate no longer names loses the row, a new one gets a row. This is the
     * only writer of that table. fk_playlist_collaborators_user_id rejects unknown users.
     */
    private function syncCollaboratorsToEntity(Playlist $playlist, PlaylistEntity $entity, bool $isNew): void
    {
        $desired = [];
        foreach ($playlist->collaboratorIds() as $userId) {
            $desired[$userId->toString()] = $userId;
        }

        // A playlist created in this save has no rows yet, so there is nothing to diff against.
        /** @var list<PlaylistCollaboratorEntity> $existing */
        $existing = $isNew ? [] : $this->entityManager
            ->getRepository(PlaylistCollaboratorEntity::class)
            ->createQueryBuilder('c')
            ->innerJoin('c.playlist', 'p')
            ->where('p.id = :playlistId')
            ->setParameter('playlistId', $playlist->getId())
            ->getQuery()
            ->getResult();

        $existingByUser = [];
        foreach ($existing as $row) {
            $existingByUser[$row->getUser()->getId()->toString()] = $row;
        }

        foreach ($existingByUser as $userIdStr => $row) {
            if (!array_key_exists($userIdStr, $desired)) {
                $this->entityManager->remove($row);
            }
        }

        foreach (array_diff_key($desired, $existingByUser) as $userId) {
            $user = $this->entityManager->getReference(UserEntity::class, $userId);
            $this->entityManager->persist(new PlaylistCollaboratorEntity($entity, $user));
        }
    }
}
