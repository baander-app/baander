<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Repository;

use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Model\AlbumState;
use App\Catalog\Domain\Model\Song;
use App\Catalog\Domain\Repository\AlbumRepositoryInterface;
use App\Catalog\Domain\ValueObject\ArtistRole;
use App\Catalog\Domain\ValueObject\MusicbrainzId;
use App\Catalog\Infrastructure\Doctrine\Entity\AlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistAlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\ArtistEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\GenreAlbumEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\GenreEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\SongEntity;
use App\Catalog\Infrastructure\Doctrine\ManagedHolders;
use App\Catalog\Infrastructure\Doctrine\Query\CatalogReadScopeQuery;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Media\Infrastructure\Doctrine\Entity\ImageEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\Model\SearchResult;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Shared\Infrastructure\Doctrine\Repository\PgroongaSearchTrait;
use App\Shared\Infrastructure\Doctrine\Repository\RefreshedEntityTrait;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Pure domain repository for albums.
 */
final class AlbumRepository implements AlbumRepositoryInterface
{
    use PerformerCreditsTrait;
    use PgroongaSearchTrait;
    use RefreshedEntityTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    )
    {
    }

    public function findVisibleByPublicId(PublicId $publicId, LibraryReadScope $scope): ?Album
    {
        $entity = $this
            ->visibleQuery($scope)
            ->andWhere('visible.publicId = :publicId')
            ->setParameter('publicId', $publicId)
            ->getQuery()
            ->getOneOrNullResult();
        return $entity === null ? null : $this->toDomain($entity);
    }

    public function findVisibleByUuid(Uuid $uuid, LibraryReadScope $scope): ?Album
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

    private function visibleQuery(LibraryReadScope $scope): QueryBuilder
    {
        $qb = $this->entityManager
            ->getRepository(AlbumEntity::class)
            ->createQueryBuilder('visible');
        CatalogReadScopeQuery::apply($qb, $scope, 'album', 'visible');
        return $qb;
    }

    public function findVisibleWithSongs(Uuid $uuid, LibraryReadScope $scope): ?array
    {
        if ($this->findVisibleByUuid($uuid, $scope) === null) {
            return null;
        }
        return $this->findWithSongs($uuid);
    }

    public function getVisibleArtistNamesForAlbum(Uuid $albumId, LibraryReadScope $scope): array
    {
        if ($this->findVisibleByUuid($albumId, $scope) === null) {
            return [];
        }
        return $this->getArtistNamesForAlbum($albumId);
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

    public function findFreshByUuid(Uuid $uuid): ?Album
    {
        $entity = $this->findRefreshedEntity($this->entityManager, AlbumEntity::class, $uuid);

        return $entity === null ? null : $this->toDomain($entity);
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
     * Each album's credits under their credited names, not the artists' own names.
     *
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
                SELECT al.id as albumId, aa.creditedName AS name, aa.role
                FROM App\Catalog\Infrastructure\Doctrine\Entity\ArtistAlbumEntity aa
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
            ->getRepository(SongEntity::class)
            ->findBy(['album' => $entity]);

        $songs = array_map(
            fn(SongEntity $songEntity) => SongRepository::entityToDomain($songEntity),
            $songEntities,
        );

        return [$album, $songs];
    }

    public function search(SearchOptions $options): SearchResult
    {
        return $this->searchVisible($options, LibraryReadScope::unrestricted());
    }

    public function searchVisible(SearchOptions $options, LibraryReadScope $scope): SearchResult
    {
        if (!$options->hasQuery()) {
            // Listing mode: return all albums with offset/limit pagination
            $qb = $this->entityManager
                ->getRepository(AlbumEntity::class)
                ->createQueryBuilder('a');

            CatalogReadScopeQuery::apply($qb, $scope, 'album', 'a');
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

        $predicate = CatalogReadScopeQuery::native($scope, 'album', $options);
        $result = $this->buildScopedScoredQuery(
            $options,
            $this->entityManager,
            AlbumEntity::class,
            'albums',
            'title',
            $predicate['predicate'],
            $predicate['parameters'],
            $predicate['types'],
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
     * @return list<Uuid>
     */
    public function findCoverlessAlbumIdsAfter(?Uuid $after = null, int $limit = 500): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('Coverless album page size must be positive.');
        }

        $qb = $this->entityManager
            ->getRepository(AlbumEntity::class)
            ->createQueryBuilder('a')
            ->select('a.id')
            ->where('a.coverImage IS NULL')
            ->orderBy('a.id', 'ASC')
            ->setMaxResults($limit);

        if ($after !== null) {
            $qb->andWhere('a.id > :after')->setParameter('after', $after, 'uuid');
        }

        /** @var list<string> $ids */
        $ids = $qb->getQuery()->getSingleColumnResult();

        return array_map(static fn (string $id): Uuid => Uuid::fromString($id), $ids);
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
        $entity = $this->entityManager->find(AlbumEntity::class, $album->getId());
        if ($entity === null) {
            return;
        }

        // The statement below acts on the database, so the unit of work's pending changes go first.
        $this->entityManager->flush();
        // A song, credit, genre link or match state is deleted with the album; a cover or a play
        // only loses its reference and is read again after the delete.
        $stale = ManagedHolders::release($this->entityManager, $entity);

        $this->entityManager->createQuery('DELETE ' . AlbumEntity::class . ' album WHERE album.id = :id')
            ->setParameter('id', $album->getId())
            ->execute();

        foreach ($stale as $holder) {
            $this->entityManager->refresh($holder);
        }
    }

    public function lockForArtistCredit(Uuid $albumId): ?array
    {
        // SQL because DQL locks rows only FOR UPDATE or FOR SHARE. FOR UPDATE would make every
        // song and credit insert into the album wait, since their foreign keys take FOR KEY SHARE
        // on the album's row; FOR NO KEY UPDATE makes only the deciders of its credit take turns.
        $locked = $this->entityManager->getConnection()->fetchOne(
            'SELECT id FROM albums WHERE id = :id FOR NO KEY UPDATE',
            ['id' => $albumId->toString()],
        );
        if ($locked === false) {
            return null;
        }

        // Statements of their own after the lock, so under READ COMMITTED they read what a decider
        // that held the lock first committed.
        $credits = (int) $this->entityManager->createQuery(
            'SELECT COUNT(credit.id) FROM ' . ArtistAlbumEntity::class . ' credit WHERE credit.album = :albumId',
        )
            ->setParameter('albumId', $albumId)
            ->getSingleScalarResult();
        if ($credits > 0) {
            return null;
        }

        return $this->primaryCreditsBySong($albumId);
    }

    public function addArtistCredit(Uuid $albumId, Uuid $artistId, string $role, string $creditedName): void
    {
        $albumEntity = $this->entityManager->find(AlbumEntity::class, $albumId)
            ?? throw new \RuntimeException(sprintf('Album not found: %s', $albumId->toString()));
        $artistEntity = $this->entityManager->find(ArtistEntity::class, $artistId)
            ?? throw new \RuntimeException(sprintf('Artist not found: %s', $artistId->toString()));

        $this->entityManager->persist(new ArtistAlbumEntity($artistEntity, $albumEntity, $role, $creditedName));
    }

    public function lockInIdOrder(array $albumIds): void
    {
        $connection = $this->entityManager->getConnection();
        // Outside a transaction the row locks would end with their own statement.
        if (!$connection->isTransactionActive()) {
            throw new \LogicException('Lock album rows inside the transaction that writes them.');
        }
        if ($albumIds === []) {
            return;
        }

        // SQL because DQL locks rows only FOR UPDATE or FOR SHARE. FOR UPDATE would also make every
        // song and credit insert into the album wait, since their foreign keys lock the row FOR KEY
        // SHARE. The rows are locked in the order the sort returns them, so writers of the same
        // albums never wait on each other in a cycle.
        $connection->executeQuery(
            'SELECT id FROM albums WHERE id IN (:ids) ORDER BY id FOR NO KEY UPDATE',
            ['ids' => array_map(static fn (Uuid $id): string => $id->toString(), $albumIds)],
            ['ids' => ArrayParameterType::STRING],
        )->free();
    }

    public function lockForMerge(Uuid $sourceId, Uuid $targetId): void
    {
        $connection = $this->entityManager->getConnection();
        // Outside a transaction the row locks would end with their own statement.
        if (!$connection->isTransactionActive()) {
            throw new \LogicException('Lock the albums of a merge inside the transaction that merges them.');
        }

        // SQL because DQL locks rows only FOR UPDATE or FOR SHARE, and one statement locks all its
        // rows in one strength. The source is deleted at the end of the merge: FOR UPDATE makes the
        // foreign key of a row inserted for it meanwhile wait, so that insert fails instead of being
        // deleted with the source. The target only changes, as in a match apply. The binary form
        // sorts as PostgreSQL sorts uuid values, so the rows are taken in the ID order of
        // lockInIdOrder() and two writers of the same albums never wait on each other in a cycle.
        $locks = [[$sourceId, 'FOR UPDATE'], [$targetId, 'FOR NO KEY UPDATE']];
        usort($locks, static fn (array $a, array $b): int => strcmp($a[0]->toBinary(), $b[0]->toBinary()));
        foreach ($locks as [$albumId, $strength]) {
            $connection->executeQuery('SELECT id FROM albums WHERE id = :id ' . $strength, ['id' => $albumId->toString()])->free();
        }
    }

    public function replacePerformerCredits(Uuid $albumId, array $credits): bool
    {
        return $this->writePerformerCredits(ArtistAlbumEntity::class, 'album', $albumId, $credits);
    }

    public function replaceGenres(Uuid $albumId, array $genreIds): bool
    {
        $wanted = array_map(static fn (Uuid $id): string => $id->toString() |> strtolower(...), $genreIds) |> array_unique(...);
        $current = $this->entityManager
            ->createQuery('SELECT link.id AS id, IDENTITY(link.genre) AS genreId FROM ' . GenreAlbumEntity::class . ' link WHERE link.album = :album')
            ->setParameter('album', $albumId)
            ->getResult();
        $linked = array_map(static fn (array $row): string => strtolower((string) $row['genreId']), $current);

        $stale = array_filter($current, static fn (array $row): bool => !in_array(strtolower((string) $row['genreId']), $wanted, true))
            |> (static fn (array $rows): array => array_column($rows, 'id'));
        if ($stale !== []) {
            $this->entityManager->createQuery('DELETE ' . GenreAlbumEntity::class . ' link WHERE link.id IN (:ids)')
                ->setParameter('ids', array_map(static fn (Uuid $id): string => $id->toString(), $stale))
                ->execute();
            $unitOfWork = $this->entityManager->getUnitOfWork();
            foreach ($stale as $id) {
                $link = $unitOfWork->tryGetById(['id' => $id], GenreAlbumEntity::class);
                if ($link !== false) {
                    $this->entityManager->detach($link);
                }
            }
        }

        $missing = array_diff($wanted, $linked);
        if ($missing !== []) {
            $album = $this->entityManager->find(AlbumEntity::class, $albumId)
                ?? throw new \RuntimeException(sprintf('Album not found: %s', $albumId->toString()));
            foreach ($missing as $genreId) {
                $genre = $this->entityManager->find(GenreEntity::class, Uuid::fromString($genreId))
                    ?? throw new \RuntimeException(sprintf('Genre not found: %s', $genreId));
                $this->entityManager->persist(new GenreAlbumEntity($genre, $album));
            }
        }

        return $stale !== [] || $missing !== [];
    }

    /**
     * The album's credits under their credited names, not the artists' own names.
     *
     * @return array<int, array{name: string, role: string|null}>
     */
    public function getArtistNamesForAlbum(Uuid $albumId): array
    {
        $rows = $this->entityManager->createQuery(
            <<<'DQL'
                SELECT aa.creditedName AS name, aa.role
                FROM App\Catalog\Infrastructure\Doctrine\Entity\ArtistAlbumEntity aa
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
     * Every song of the album with its primary credits, an empty list for a song without one.
     *
     * @return array<string, list<array{artistId: Uuid, artistName: string, creditedName: string}>> by song ID
     */
    private function primaryCreditsBySong(Uuid $albumId): array
    {
        $rows = $this->entityManager->createQuery(
            <<<'DQL'
                SELECT song.id AS songId, artist.id AS artistId, artist.name AS artistName, credit.creditedName AS creditedName
                FROM App\Catalog\Infrastructure\Doctrine\Entity\SongEntity song
                LEFT JOIN App\Catalog\Infrastructure\Doctrine\Entity\ArtistSongEntity credit WITH credit.song = song AND credit.role = :primary
                LEFT JOIN credit.artist artist
                WHERE song.album = :albumId
                DQL,
        )
            ->setParameter('albumId', $albumId)
            ->setParameter('primary', ArtistRole::Primary->value)
            ->getResult();

        $credits = [];
        foreach ($rows as $row) {
            $songId = $row['songId']->toString();
            $credits[$songId] ??= [];
            if ($row['artistId'] !== null) {
                $credits[$songId][] = ['artistId' => $row['artistId'], 'artistName' => $row['artistName'], 'creditedName' => $row['creditedName']];
            }
        }

        return $credits;
    }

    /**
     * @param list<array{field: string, operator: string, value: mixed}> $filters
     */
    private function applyAlbumFilters(QueryBuilder $qb, array $filters): void
    {
        foreach ($filters as $filter) {
            match ($filter['field']) {
                'artistId' => $this->applyArtistFilter($qb, $filter['value']),
                'genre' => $this->applyGenreFilter($qb, $filter['value']),
                default => null,
            };
        }
    }

    private function applyArtistFilter(QueryBuilder $qb, string $artistPublicId): void
    {
        $artistEntity = $this->entityManager
            ->getRepository(ArtistEntity::class)
            ->findOneBy(['publicId' => PublicId::fromString($artistPublicId)]);

        if ($artistEntity === null) {
            // Force empty result by always-false condition
            $qb->andWhere('1 = 0');
            return;
        }

        $qb->andWhere(<<<'DQL'
            EXISTS (
                SELECT aa_filter.id
                FROM App\Catalog\Infrastructure\Doctrine\Entity\ArtistAlbumEntity aa_filter
                WHERE aa_filter.album = a.id AND aa_filter.artist = :artist_filter_id
            )
            DQL)
            ->setParameter('artist_filter_id', $artistEntity->getId());
    }

    private function applyGenreFilter(QueryBuilder $qb, string $genreSlug): void
    {
        $qb->andWhere($qb->expr()->in(
            'a.id',
            'SELECT IDENTITY(s_gf.album) FROM App\Catalog\Infrastructure\Doctrine\Entity\SongEntity s_gf ' .
            'JOIN App\Catalog\Infrastructure\Doctrine\Entity\GenreSongEntity gs_gf WITH gs_gf.song = s_gf.id ' .
            'JOIN gs_gf.genre g_gf WHERE g_gf.slug = :genre_slug',
        ))->setParameter('genre_slug', $genreSlug);
    }

    private function applyAlbumSort(QueryBuilder $qb, SearchOptions $options): void
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
            releaseMbid: $entity->getReleaseMbid(),
            matchedAt: $entity->getMatchedAt(),
        ));
    }

    private function syncToEntity(Album $album, AlbumEntity $entity): void
    {
        $entity->setTitle($album->getTitle());
        $entity->setType($album->getType());
        $entity->setMbid($album->getMbid());
        $entity->setReleaseMbid($album->getReleaseMbid());
        $entity->setMatchedAt($album->getMatchedAt());
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
                ->getRepository(ImageEntity::class)
                ->find($album->getCoverImageId());
            $entity->setCoverImage($imageEntity);
        } else {
            $entity->setCoverImage(null);
        }
    }
}
