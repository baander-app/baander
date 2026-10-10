<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Repository;

use App\Catalog\Domain\Model\Artist;
use App\Catalog\Domain\Model\ArtistState;
use App\Catalog\Domain\Repository\ArtistRepositoryInterface;
use App\Catalog\Domain\ValueObject\MusicbrainzId;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistAlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistSongEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\GenreSongEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\SongEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Query\CatalogReadScopeQuery;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\Model\SearchResult;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Doctrine\Repository\RefreshedEntityTrait;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Shared\Infrastructure\Doctrine\Repository\PgroongaSearchTrait;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Pure domain repository for artists.
 */
final class ArtistRepository implements ArtistRepositoryInterface
{
    use PgroongaSearchTrait;
    use RefreshedEntityTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function findVisibleByPublicId(PublicId $publicId, LibraryReadScope $scope): ?Artist
    {
        $entity = $this
            ->visibleQuery($scope)
            ->andWhere('visible.publicId = :publicId')
            ->setParameter('publicId', $publicId)
            ->getQuery()
            ->getOneOrNullResult();
        return $entity === null ? null : $this->toDomain($entity);
    }

    public function findVisibleByUuid(Uuid $uuid, LibraryReadScope $scope): ?Artist
    {
        $entity = $this
            ->visibleQuery($scope)
            ->andWhere('visible.id = :id')
            ->setParameter('id', $uuid)
            ->getQuery()
            ->getOneOrNullResult();
        return $entity === null ? null : $this->toDomain($entity);
    }

    public function countVisible(LibraryReadScope $scope): int
    {
        return (int) $this
            ->visibleQuery($scope)
            ->select('COUNT(visible.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function visibleQuery(LibraryReadScope $scope): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->entityManager
            ->getRepository(ArtistEntity::class)
            ->createQueryBuilder('visible');
        CatalogReadScopeQuery::apply($qb, $scope, 'artist', 'visible');
        return $qb;
    }

    public function save(Artist $artist): void
    {
        $entity = $this->findEntityOrCreate($artist);
        $this->syncToEntity($artist, $entity);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function persist(Artist $artist): void
    {
        $entity = $this->findEntityOrCreate($artist);
        $this->syncToEntity($artist, $entity);
        $this->entityManager->persist($entity);
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }

    public function findByUuid(Uuid $uuid): ?Artist
    {
        $entity = $this->entityManager
            ->getRepository(ArtistEntity::class)
            ->find($uuid);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findFreshByUuid(Uuid $uuid): ?Artist
    {
        $entity = $this->findRefreshedEntity($this->entityManager, ArtistEntity::class, $uuid);

        return $entity === null ? null : $this->toDomain($entity);
    }

    public function findByPublicId(PublicId $publicId): ?Artist
    {
        $entity = $this->entityManager
            ->getRepository(ArtistEntity::class)
            ->findOneBy(['publicId' => $publicId]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findByUuids(array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }

        $entities = $this->entityManager
            ->getRepository(ArtistEntity::class)
            ->findBy(['id' => $uuids]);

        $result = [];
        foreach ($entities as $entity) {
            $artist = $this->toDomain($entity);
            $result[$artist->getId()->toString()] = $artist;
        }

        return $result;
    }

    public function findByMbid(?MusicbrainzId $mbid): ?Artist
    {
        if ($mbid === null || $mbid->isEmpty()) {
            return null;
        }

        $entity = $this->entityManager
            ->getRepository(ArtistEntity::class)
            ->findOneBy(['mbid' => $mbid->toString()]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function search(SearchOptions $options): SearchResult
    {
        return $this->searchVisible($options, LibraryReadScope::unrestricted());
    }

    public function searchVisible(SearchOptions $options, LibraryReadScope $scope): SearchResult
    {
        if (!$options->hasQuery()) {
            // Listing mode: return all artists with offset/limit pagination
            $qb = $this->entityManager
                ->getRepository(ArtistEntity::class)
                ->createQueryBuilder('a');

            CatalogReadScopeQuery::apply($qb, $scope, 'artist', 'a');
            $this->applyArtistFilters($qb, $options->getFilters(), $scope);

            $countQb = clone $qb;
            $countQb->resetDQLPart('select')
                ->resetDQLPart('orderBy')
                ->setFirstResult(0)
                ->setMaxResults(null)
                ->select('COUNT(a)');
            $total = (int) $countQb->getQuery()->getSingleScalarResult();

            $this->applyArtistSort($qb, $options);

            $qb->setMaxResults($options->getLimit())
                ->setFirstResult($options->getOffset());

            $entities = $qb->getQuery()->getResult();
            $artists = array_map(fn(ArtistEntity $entity) => $this->toDomain($entity), $entities);

            return SearchResult::create($artists, $total);
        }

        $predicate = CatalogReadScopeQuery::native($scope, 'artist', $options);
        $result = $this->buildScopedScoredQuery(
            $options,
            $this->entityManager,
            ArtistEntity::class,
            'artists',
            'name',
            $predicate['predicate'],
            $predicate['parameters'],
            $predicate['types'],
        );

        $artists = array_map(fn(ArtistEntity $entity) => $this->toDomain($entity), $result['entities']);

        return SearchResult::create($artists, $result['total'], $result['highestScore']);
    }

    public function findByName(string $name): ?Artist
    {
        $entity = $this->entityManager
            ->getRepository(ArtistEntity::class)
            ->findOneBy(['name' => $name]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findOrCreateByName(string $name): Artist
    {
        $existing = $this->findByName($name);

        if ($existing !== null) {
            return $existing;
        }

        $artist = Artist::create($name);
        $entity = new ArtistEntity(
            $artist->getPublicId(),
            $artist->getName(),
        );
        $this->entityManager->persist($entity);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $e) {
            $existing = $this->findByName($name);
            if ($existing !== null) {
                return $existing;
            }
            throw $e;
        }

        return $this->toDomain($entity);
    }

    public function count(): int
    {
        return (int) $this->entityManager
            ->getRepository(ArtistEntity::class)
            ->count([]);
    }

    public function delete(Artist $artist): void
    {
        $entity = $this->entityManager
            ->getRepository(ArtistEntity::class)
            ->find($artist->getId());

        if ($entity !== null) {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        }
    }

    // --- Internal ---

    /**
     * @param list<array{field: string, operator: string, value: mixed}> $filters
     */
    private function applyArtistFilters(\Doctrine\ORM\QueryBuilder $qb, array $filters, LibraryReadScope $scope): void
    {
        foreach ($filters as $filter) {
            match ($filter['field']) {
                'genre' => $this->applyGenreFilter($qb, $filter['value'], $scope),
                default => null,
            };
        }
    }

    private function applyGenreFilter(\Doctrine\ORM\QueryBuilder $qb, string $genreSlug, LibraryReadScope $scope): void
    {
        $libraryPredicate = $scope->isUnrestricted()
            ? ''
            : ($scope->getLibraryIds() === []
                ? ' AND 1 = 0'
                : ' AND IDENTITY(al_gf.library) IN (:visible_libraries)');
        $qb->andWhere($qb->expr()->in(
            'a.id',
            'SELECT IDENTITY(ass_gf.artist) FROM App\Catalog\Infrastructure\Doctrine\Entity\ArtistSongEntity ass_gf ' .
            'JOIN App\Catalog\Infrastructure\Doctrine\Entity\GenreSongEntity gs_gf WITH gs_gf.song = ass_gf.song ' .
            'JOIN ass_gf.song s_gf JOIN s_gf.album al_gf JOIN gs_gf.genre g_gf WHERE g_gf.slug = :genre_slug' . $libraryPredicate,
        ))->setParameter('genre_slug', $genreSlug);
    }

    private function applyArtistSort(\Doctrine\ORM\QueryBuilder $qb, SearchOptions $options): void
    {
        if (!$options->hasSort()) {
            $qb->orderBy('a.name', 'ASC');
            return;
        }

        $direction = strtoupper($options->getSortOrder());

        match ($options->getSortField()) {
            'name' => $qb->orderBy('a.name', $direction),
            default => $qb->orderBy('a.name', 'ASC'),
        };
    }

    private function findEntityOrCreate(Artist $artist): ArtistEntity
    {
        $existing = $this->entityManager
            ->getRepository(ArtistEntity::class)
            ->find($artist->getId());

        if ($existing !== null) {
            return $existing;
        }

        return new ArtistEntity(
            $artist->getPublicId(),
            $artist->getName(),
            id: $artist->getId(),
        );
    }

    private function toDomain(ArtistEntity $entity): Artist
    {
        return Artist::reconstitute(new ArtistState(
            id: $entity->getId(),
            publicId: $entity->getPublicId(),
            name: $entity->getName(),
            country: $entity->getCountry(),
            gender: $entity->getGender(),
            type: $entity->getType(),
            lifeSpanBegin: $entity->getLifeSpanBegin(),
            lifeSpanEnd: $entity->getLifeSpanEnd(),
            disambiguation: $entity->getDisambiguation(),
            sortName: $entity->getSortName(),
            biography: $entity->getBiography(),
            mbid: $entity->getMbid(),
            discogsId: $entity->getDiscogsId(),
            spotifyId: $entity->getSpotifyId(),
            coverImageId: $entity->getCoverImage()?->getId(),
            lockedFields: $entity->getLockedFields(),
            createdAt: $entity->getCreatedAt(),
            updatedAt: $entity->getUpdatedAt(),
        ));
    }

    private function syncToEntity(Artist $artist, ArtistEntity $entity): void
    {
        $entity->setName($artist->getName());
        $entity->setCountry($artist->getCountry());
        $entity->setGender($artist->getGender());
        $entity->setType($artist->getType());
        $entity->setLifeSpanBegin($artist->getLifeSpanBegin());
        $entity->setLifeSpanEnd($artist->getLifeSpanEnd());
        $entity->setDisambiguation($artist->getDisambiguation());
        $entity->setSortName($artist->getSortName());
        $entity->setBiography($artist->getBiography());
        $entity->setMbid($artist->getMbid());
        $entity->setDiscogsId($artist->getDiscogsId());
        $entity->setSpotifyId($artist->getSpotifyId());
        $entity->setLockedFields($artist->getLockedFields());

        // Sync cover image relationship
        if ($artist->getCoverImageId() !== null) {
            $imageEntity = $this->entityManager
                ->getRepository(\App\Media\Infrastructure\Doctrine\Entity\ImageEntity::class)
                ->find($artist->getCoverImageId());
            $entity->setCoverImage($imageEntity);
        } else {
            $entity->setCoverImage(null);
        }
    }

    public function addSongToArtist(Uuid $artistId, Uuid $songId, string $role): bool
    {
        $artistEntity = $this->entityManager->find(ArtistEntity::class, $artistId);
        $songEntity = $this->entityManager->find(SongEntity::class, $songId);

        if ($artistEntity === null || $songEntity === null) {
            return false;
        }

        if ($this->creditLink(ArtistSongEntity::class, 'song', $artistId, $songId, $role) === null) {
            $this->entityManager->persist(new ArtistSongEntity($artistEntity, $songEntity, $role));
            $this->entityManager->flush();
        }

        return true;
    }

    public function removeSongFromArtist(Uuid $artistId, Uuid $songId): bool
    {
        return $this->removeCreditLinks(ArtistSongEntity::class, 'song', $artistId, $songId);
    }

    public function songCreditRoles(Uuid $artistId, Uuid $songId): array
    {
        return $this->creditRoles(ArtistSongEntity::class, 'song', $artistId, $songId);
    }

    public function updateSongRole(Uuid $artistId, Uuid $songId, ?string $currentRole, string $role): bool
    {
        return $this->changeCreditRole(ArtistSongEntity::class, 'song', $artistId, $songId, $currentRole, $role);
    }

    public function addAlbumToArtist(Uuid $artistId, Uuid $albumId, string $role): bool
    {
        $artistEntity = $this->entityManager->find(ArtistEntity::class, $artistId);
        $albumEntity = $this->entityManager->find(AlbumEntity::class, $albumId);

        if ($artistEntity === null || $albumEntity === null) {
            return false;
        }

        if ($this->creditLink(ArtistAlbumEntity::class, 'album', $artistId, $albumId, $role) === null) {
            $this->entityManager->persist(new ArtistAlbumEntity($artistEntity, $albumEntity, $role));
            $this->entityManager->flush();
        }

        return true;
    }

    public function removeAlbumFromArtist(Uuid $artistId, Uuid $albumId): bool
    {
        return $this->removeCreditLinks(ArtistAlbumEntity::class, 'album', $artistId, $albumId);
    }

    public function albumCreditRoles(Uuid $artistId, Uuid $albumId): array
    {
        return $this->creditRoles(ArtistAlbumEntity::class, 'album', $artistId, $albumId);
    }

    public function updateAlbumRole(Uuid $artistId, Uuid $albumId, ?string $currentRole, string $role): bool
    {
        return $this->changeCreditRole(ArtistAlbumEntity::class, 'album', $artistId, $albumId, $currentRole, $role);
    }

    /**
     * @template T of ArtistSongEntity|ArtistAlbumEntity
     * @param class-string<T>  $link
     * @param 'song'|'album'   $target the link's association to the song or album
     * @return T|null
     */
    private function creditLink(string $link, string $target, Uuid $artistId, Uuid $targetId, ?string $role): ?object
    {
        return $this->entityManager->getRepository($link)->findOneBy(['artist' => $artistId, $target => $targetId, 'role' => $role]);
    }

    /**
     * @param class-string<ArtistSongEntity|ArtistAlbumEntity> $link
     * @param 'song'|'album'                                   $target
     */
    private function removeCreditLinks(string $link, string $target, Uuid $artistId, Uuid $targetId): bool
    {
        $links = $this->entityManager->getRepository($link)->findBy(['artist' => $artistId, $target => $targetId]);
        if ($links === []) {
            return false;
        }

        foreach ($links as $credit) {
            $this->entityManager->remove($credit);
        }
        $this->entityManager->flush();

        return true;
    }

    /**
     * @param class-string<ArtistSongEntity|ArtistAlbumEntity> $link
     * @param 'song'|'album'                                   $target
     * @return list<string|null> sorted, a credit without a role first
     */
    private function creditRoles(string $link, string $target, Uuid $artistId, Uuid $targetId): array
    {
        $roles = array_map(
            static fn (ArtistSongEntity|ArtistAlbumEntity $credit): ?string => $credit->getRole(),
            $this->entityManager->getRepository($link)->findBy(['artist' => $artistId, $target => $targetId]),
        );
        sort($roles);

        return $roles;
    }

    /**
     * The unique constraint on (artist, target, role) forbids renaming a credit to a role the pair
     * already holds, so that case removes the renamed credit instead.
     *
     * @param class-string<ArtistSongEntity|ArtistAlbumEntity> $link
     * @param 'song'|'album'                                   $target
     */
    private function changeCreditRole(string $link, string $target, Uuid $artistId, Uuid $targetId, ?string $currentRole, string $role): bool
    {
        $credit = $this->creditLink($link, $target, $artistId, $targetId, $currentRole);
        if ($credit === null) {
            return false;
        }
        if ($currentRole === $role) {
            return true;
        }

        if ($this->creditLink($link, $target, $artistId, $targetId, $role) !== null) {
            $this->entityManager->remove($credit);
        } else {
            $credit->setRole($role);
        }
        $this->entityManager->flush();

        return true;
    }
}
