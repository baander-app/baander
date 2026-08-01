<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Repository;

use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\AlbumState;
use App\Catalog\Domain\Repository\AlbumRepositoryInterface;
use App\Catalog\Domain\ValueObject\MusicbrainzId;
use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistAlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistEntity;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\Model\SearchResult;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Doctrine\Repository\PgroongaSearchTrait;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Pure domain repository for albums.
 */
final class AlbumRepository implements AlbumRepositoryInterface
{
    use PgroongaSearchTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    )
    {
    }

    public function save(Album $album): void
    {
        $entity = $this->findEntityOrCreate($album);
        $this->syncToEntity($album, $entity);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function persist(Album $album): void
    {
        $entity = $this->findEntityOrCreate($album);
        $this->syncToEntity($album, $entity);
        $this->entityManager->persist($entity);
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }

    public function findByUuid(Uuid $uuid): ?Album
    {
        $entity = $this->entityManager
            ->getRepository(AlbumEntity::class)
            ->find($uuid);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findByPublicId(PublicId $publicId): ?Album
    {
        $entity = $this->entityManager
            ->getRepository(AlbumEntity::class)
            ->findOneBy(['publicId' => $publicId]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findByUuids(array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }

        $entities = $this->entityManager
            ->getRepository(AlbumEntity::class)
            ->findBy(['id' => $uuids]);

        $result = [];
        foreach ($entities as $entity) {
            $album = $this->toDomain($entity);
            $result[$album->getId()->toString()] = $album;
        }

        return $result;
    }

    /**
     * @param Uuid[] $albumIds
     * @return array<string, array<int, array{name: string, role: string|null}>> keyed by album UUID string
     */
    public function getArtistNamesForAlbums(array $albumIds): array
    {
        if ($albumIds === []) {
            return [];
        }

        $rows = $this->entityManager->createQuery(
            <<<'DQL'
                SELECT al.id as albumId, a.name, aa.role
                FROM App\Catalog\Infrastructure\Doctrine\Entity\ArtistAlbumEntity aa
                JOIN aa.artist a
                JOIN aa.album al
                WHERE al.id IN (:albumIds)
                DQL,
        )
            ->setParameter('albumIds', $albumIds)
            ->getResult();

        $result = [];
        foreach ($rows as $row) {
            $albumId = $row['albumId']->toString();
            if (!isset($result[$albumId])) {
                $result[$albumId] = [];
            }
            $result[$albumId][] = ['name' => $row['name'], 'role' => $row['role']];
        }

        return $result;
    }

    public function findByMbid(?MusicbrainzId $mbid): ?Album
    {
        if ($mbid === null || $mbid->isEmpty()) {
            return null;
        }

        $entity = $this->entityManager
            ->getRepository(AlbumEntity::class)
            ->findOneBy(['mbid' => $mbid->toString()]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findByMbidAndLibrary(?MusicbrainzId $mbid, Uuid $libraryId): ?Album
    {
        if ($mbid === null || $mbid->isEmpty()) {
            return null;
        }

        $entity = $this->entityManager
            ->getRepository(AlbumEntity::class)
            ->findOneBy(['mbid' => $mbid->toString(), 'library' => $libraryId]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findByTitleAndLibrary(string $title, Uuid $libraryId): ?Album
    {
        $entity = $this->entityManager
            ->getRepository(AlbumEntity::class)
            ->findOneBy(['title' => $title, 'library' => $libraryId]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    /**
     * @return Album[]
     */
    public function findByLibrary(Uuid $libraryId): array
    {
        $entities = $this->entityManager
            ->getRepository(AlbumEntity::class)
            ->findBy(['library' => $libraryId]);

        return array_map(fn(AlbumEntity $entity) => $this->toDomain($entity), $entities);
    }

    public function findWithSongs(Uuid $uuid): ?array
    {
        $entity = $this->entityManager
            ->getRepository(AlbumEntity::class)
            ->find($uuid);

        if ($entity === null) {
            return null;
        }

        $album = $this->toDomain($entity);

        $songEntities = $this->entityManager
            ->getRepository(\App\Catalog\Infrastructure\Doctrine\Entity\SongEntity::class)
            ->findBy(['album' => $entity]);

        $songs = array_map(
            fn(\App\Catalog\Infrastructure\Doctrine\Entity\SongEntity $songEntity) => SongRepository::entityToDomain($songEntity),
            $songEntities,
        );

        return [$album, $songs];
    }

    public function search(SearchOptions $options): SearchResult
    {
        if (!$options->hasQuery()) {
            // Listing mode: return all albums with offset/limit pagination
            $qb = $this->entityManager
                ->getRepository(AlbumEntity::class)
                ->createQueryBuilder('a');

            $this->applyAlbumFilters($qb, $options->getFilters());

            $countQb = clone $qb;
            $countQb->resetDQLPart('select')
                ->resetDQLPart('orderBy')
                ->setFirstResult(0)
                ->setMaxResults(null)
                ->select('COUNT(a)');
            $total = (int) $countQb->getQuery()->getSingleScalarResult();

            $this->applyAlbumSort($qb, $options);

            $qb->setMaxResults($options->getLimit())
                ->setFirstResult($options->getOffset());

            $entities = $qb->getQuery()->getResult();
            $albums = array_map(fn(AlbumEntity $entity) => $this->toDomain($entity), $entities);

            return SearchResult::create($albums, $total);
        }

        $result = $this->buildScoredQuery(
            $options,
            $this->entityManager,
            AlbumEntity::class,
            'albums',
            'title',
        );

        $albums = array_map(fn(AlbumEntity $entity) => $this->toDomain($entity), $result['entities']);

        return SearchResult::create($albums, $result['total'], $result['highestScore']);
    }

    public function count(): int
    {
        return (int)$this->entityManager
            ->getRepository(AlbumEntity::class)
            ->count([]);
    }

    public function countCoverlessAlbums(): int
    {
        return (int)$this->entityManager
            ->getRepository(AlbumEntity::class)
            ->count(['coverImage' => null]);
    }

    /**
     * @return Uuid[]
     */
    public function findCoverlessAlbumIds(int $limit = 500, int $offset = 0): array
    {
        $qb = $this->entityManager
            ->getRepository(AlbumEntity::class)
            ->createQueryBuilder('a')
            ->select('a.id')
            ->where('a.coverImage IS NULL')
            ->orderBy('a.id', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        /** @var string[] $ids */
        $ids = $qb->getQuery()->getSingleColumnResult();

        return array_map(static fn(string $id): Uuid => Uuid::fromString($id), $ids);
    }

    /**
     * @return Uuid[]
     */
    public function findCoverlessAlbumIdsByLibrary(Uuid $libraryId): array
    {
        $qb = $this->entityManager
            ->getRepository(AlbumEntity::class)
            ->createQueryBuilder('a')
            ->select('a.id')
            ->where('a.coverImage IS NULL')
            ->andWhere('a.library = :libraryId')
            ->orderBy('a.id', 'ASC')
            ->setParameter('libraryId', $libraryId);

        /** @var string[] $ids */
        $ids = $qb->getQuery()->getSingleColumnResult();

        return array_map(static fn(string $id): Uuid => Uuid::fromString($id), $ids);
    }

    /**
     * @return Uuid[]
     */
    public function findAlbumIdsByLibrary(Uuid $libraryId): array
    {
        $qb = $this->entityManager
            ->getRepository(AlbumEntity::class)
            ->createQueryBuilder('a')
            ->select('a.id')
            ->where('a.library = :libraryId')
            ->orderBy('a.id', 'ASC')
            ->setParameter('libraryId', $libraryId);

        /** @var string[] $ids */
        $ids = $qb->getQuery()->getSingleColumnResult();

        return array_map(static fn(string $id): Uuid => Uuid::fromString($id), $ids);
    }

    public function delete(Album $album): void
    {
        $entity = $this->entityManager
            ->getRepository(AlbumEntity::class)
            ->find($album->getId());

        if ($entity !== null) {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        }
    }

    public function linkArtistToAlbum(Uuid $albumId, string $artistName, string $role): void
    {
        $albumEntity = $this->entityManager
            ->getRepository(AlbumEntity::class)
            ->find($albumId);

        if ($albumEntity === null) {
            return;
        }

        $artistEntity = $this->entityManager
            ->getRepository(ArtistEntity::class)
            ->findOneBy(['name' => $artistName]);

        if ($artistEntity === null) {
            return;
        }

        // Check for existing link to avoid unique constraint violation
        $existing = $this->entityManager
            ->getRepository(ArtistAlbumEntity::class)
            ->findOneBy([
                'artist' => $artistEntity->getId(),
                'album' => $albumEntity->getId(),
                'role' => $role,
            ]);

        if ($existing !== null) {
            return;
        }

        $artistAlbum = new ArtistAlbumEntity($artistEntity, $albumEntity, $role);
        $this->entityManager->persist($artistAlbum);
    }

    /**
     * @return array<int, array{name: string, role: string|null}>
     */
    public function getArtistNamesForAlbum(Uuid $albumId): array
    {
        $rows = $this->entityManager->createQuery(
            <<<'DQL'
                SELECT a.name, aa.role
                FROM App\Catalog\Infrastructure\Doctrine\Entity\ArtistAlbumEntity aa
                JOIN aa.artist a
                JOIN aa.album al
                WHERE al.id = :albumId
                DQL,
        )
            ->setParameter('albumId', $albumId)
            ->getResult();

        return array_map(
            static fn(array $row): array => ['name' => $row['name'], 'role' => $row['role']],
            $rows,
        );
    }

    // --- Internal ---

    /**
     * @param list<array{field: string, operator: string, value: mixed}> $filters
     */
    private function applyAlbumFilters(\Doctrine\ORM\QueryBuilder $qb, array $filters): void
    {
        foreach ($filters as $filter) {
            match ($filter['field']) {
                'artistId' => $this->applyArtistFilter($qb, $filter['value']),
                'genre' => $this->applyGenreFilter($qb, $filter['value']),
                default => null,
            };
        }
    }

    private function applyArtistFilter(\Doctrine\ORM\QueryBuilder $qb, string $artistPublicId): void
    {
        $artistEntity = $this->entityManager
            ->getRepository(ArtistEntity::class)
            ->findOneBy(['publicId' => PublicId::fromString($artistPublicId)]);

        if ($artistEntity === null) {
            // Force empty result by always-false condition
            $qb->andWhere('1 = 0');
            return;
        }

        $qb->innerJoin(
            ArtistAlbumEntity::class,
            'aa_filter',
            'WITH',
            'aa_filter.album = a.id AND aa_filter.artist = :artist_filter_id',
        )->setParameter('artist_filter_id', $artistEntity->getId());
    }

    private function applyGenreFilter(\Doctrine\ORM\QueryBuilder $qb, string $genreSlug): void
    {
        $qb->andWhere($qb->expr()->in(
            'a.id',
            'SELECT IDENTITY(s_gf.album) FROM App\Catalog\Infrastructure\Doctrine\Entity\SongEntity s_gf ' .
            'JOIN App\Catalog\Infrastructure\Doctrine\Entity\GenreSongEntity gs_gf WITH gs_gf.song = s_gf.id ' .
            'JOIN gs_gf.genre g_gf WHERE g_gf.slug = :genre_slug',
        ))->setParameter('genre_slug', $genreSlug);
    }

    private function applyAlbumSort(\Doctrine\ORM\QueryBuilder $qb, SearchOptions $options): void
    {
        if (!$options->hasSort()) {
            $qb->orderBy('a.title', 'ASC');
            return;
        }

        $direction = strtoupper($options->getSortOrder());

        match ($options->getSortField()) {
            'title' => $qb->orderBy('a.title', $direction),
            'year' => $qb->orderBy('a.year', $direction)->addOrderBy('a.title', 'ASC'),
            'artist' => $qb->leftJoin(ArtistAlbumEntity::class, 'aa_sort', 'WITH', 'aa_sort.album = a.id')
                ->leftJoin(ArtistEntity::class, 'ar_sort', 'WITH', 'ar_sort.id = aa_sort.artist')
                ->groupBy('a.id')
                ->orderBy('MIN(ar_sort.name)', $direction)
                ->addOrderBy('a.title', 'ASC'),
            'added' => $qb->orderBy('a.createdAt', $direction)->addOrderBy('a.title', 'ASC'),
            default => $qb->orderBy('a.title', 'ASC'),
        };
    }

    private function findEntityOrCreate(Album $album): AlbumEntity
    {
        $existing = $this->entityManager
            ->getRepository(AlbumEntity::class)
            ->find($album->getId());

        if ($existing !== null) {
            return $existing;
        }

        $libraryEntity = $this->entityManager
            ->getRepository(LibraryEntity::class)
            ->find($album->getLibraryId());

        return new AlbumEntity(
            $album->getPublicId(),
            $libraryEntity,
            $album->getTitle(),
            $album->getType(),
            id: $album->getId(),
        );
    }

    private function toDomain(AlbumEntity $entity): Album
    {
        return Album::reconstitute(new AlbumState(
            id: $entity->getId(),
            publicId: $entity->getPublicId(),
            libraryId: $entity->getLibrary()->getId(),
            title: $entity->getTitle(),
            type: $entity->getType(),
            mbid: $entity->getMbid(),
            discogsId: $entity->getDiscogsId(),
            spotifyId: $entity->getSpotifyId(),
            year: $entity->getYear(),
            label: $entity->getLabel(),
            catalogNumber: $entity->getCatalogNumber(),
            barcode: $entity->getBarcode(),
            country: $entity->getCountry(),
            language: $entity->getLanguage(),
            disambiguation: $entity->getDisambiguation(),
            annotation: $entity->getAnnotation(),
            coverImageId: $entity->getCoverImage()?->getId(),
            lockedFields: $entity->getLockedFields(),
            mergedFrom: $entity->getMergedFrom(),
            createdAt: $entity->getCreatedAt(),
            updatedAt: $entity->getUpdatedAt(),
        ));
    }

    private function syncToEntity(Album $album, AlbumEntity $entity): void
    {
        $entity->setTitle($album->getTitle());
        $entity->setType($album->getType());
        $entity->setMbid($album->getMbid());
        $entity->setDiscogsId($album->getDiscogsId());
        $entity->setSpotifyId($album->getSpotifyId());
        $entity->setYear($album->getYear());
        $entity->setLabel($album->getLabel());
        $entity->setCatalogNumber($album->getCatalogNumber());
        $entity->setBarcode($album->getBarcode());
        $entity->setCountry($album->getCountry());
        $entity->setLanguage($album->getLanguage());
        $entity->setDisambiguation($album->getDisambiguation());
        $entity->setAnnotation($album->getAnnotation());
        $entity->setLockedFields($album->getLockedFields());
        $entity->setMergedFrom($album->getMergedFrom());

        // Sync cover image relationship
        if ($album->getCoverImageId() !== null) {
            $imageEntity = $this->entityManager
                ->getRepository(\App\Media\Infrastructure\Doctrine\Entity\ImageEntity::class)
                ->find($album->getCoverImageId());
            $entity->setCoverImage($imageEntity);
        } else {
            $entity->setCoverImage(null);
        }
    }
}
