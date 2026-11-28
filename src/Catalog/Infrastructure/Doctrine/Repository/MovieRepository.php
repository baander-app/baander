<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Repository;

use App\Catalog\Domain\Model\Movie;
use App\Catalog\Domain\Model\MovieState;
use App\Catalog\Domain\Repository\MovieRepositoryInterface;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\MoviePersonCreditEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\MoviePersonCreditStateEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieVideoEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\VideoEntity;
use App\Catalog\Infrastructure\Doctrine\Query\CatalogReadScopeQuery;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\Model\SearchResult;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Shared\Infrastructure\Doctrine\Repository\PgroongaSearchTrait;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;

/**
 * Pure domain repository for movies.
 */
final class MovieRepository implements MovieRepositoryInterface
{
    use PgroongaSearchTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function findVisibleByPublicId(PublicId $publicId, LibraryReadScope $scope): ?Movie
    {
        $entity = $this
            ->visibleQuery($scope)
            ->andWhere('visible.publicId = :publicId')
            ->setParameter('publicId', $publicId)
            ->getQuery()
            ->getOneOrNullResult();
        return $entity === null ? null : $this->toDomain($entity);
    }

    public function findVisibleByUuid(Uuid $uuid, LibraryReadScope $scope): ?Movie
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

    public function findVisibleByUuids(array $ids, LibraryReadScope $scope): array
    {
        if (count($ids) > 100) {
            throw new \InvalidArgumentException('Load at most 100 movies in one batch.');
        }
        if ($ids === []) {
            return [];
        }
        $strings = array_map(static fn(Uuid $id): string => $id->toString(), $ids);
        $entities = $this->visibleQuery($scope)
            ->andWhere('visible.id IN (:ids)')
            ->setParameter('ids', $strings, ArrayParameterType::STRING)
            ->innerJoin('visible.library', 'movie_library')
            ->addSelect('movie_library')
            ->getQuery()
            ->getResult();
        $byId = [];
        foreach ($entities as $entity) {
            $byId[$entity->getId()->toString()] = $entity;
        }
        if ($byId === []) {
            return [];
        }
        $links = $this->entityManager->createQueryBuilder()
            ->select('link', 'video')
            ->from(MovieVideoEntity::class, 'link')
            ->innerJoin('link.video', 'video')
            ->where('IDENTITY(link.movie) IN (:ids)')
            ->setParameter('ids', array_keys($byId), ArrayParameterType::STRING)
            ->orderBy('link.sortOrder', 'ASC')
            ->addOrderBy('link.id', 'ASC')
            ->getQuery()
            ->getResult();
        $linksByMovie = [];
        foreach ($links as $link) {
            $linksByMovie[$link->getMovie()->getId()->toString()][] = $link;
        }
        $movies = [];
        foreach ($strings as $id) {
            if (isset($byId[$id])) {
                $movies[] = $this->toDomain($byId[$id], $linksByMovie[$id] ?? []);
            }
        }

        return $movies;
    }

    public function movieIdsByLibraryAfter(Uuid $libraryId, ?Uuid $after = null, int $limit = 100): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('movie.id AS movieId')
            ->from(MovieEntity::class, 'movie')
            ->where('IDENTITY(movie.library) = :libraryId')
            ->setParameter('libraryId', $libraryId, 'uuid')
            ->orderBy('movie.id', 'ASC')
            ->setMaxResults(self::pageSize($limit));
        if ($after !== null) {
            $query->andWhere('movie.id > :after')->setParameter('after', $after, 'uuid');
        }
        $rows = $query->getQuery()->getArrayResult();

        return array_map(static fn(array $row): Uuid => $row['movieId'] instanceof Uuid
            ? $row['movieId']
            : Uuid::fromString($row['movieId']), $rows);
    }

    private static function pageSize(int $limit): int
    {
        return max(1, min(100, $limit));
    }

    private function visibleQuery(LibraryReadScope $scope): QueryBuilder
    {
        $qb = $this->entityManager
            ->getRepository(MovieEntity::class)
            ->createQueryBuilder('visible');
        CatalogReadScopeQuery::apply($qb, $scope, 'movie', 'visible');
        return $qb;
    }

    public function save(Movie $movie): void
    {
        $entity = $this->findEntityOrCreate($movie);
        $this->syncToEntity($movie, $entity);
        $this->syncMovieVideos($movie, $entity);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function persist(Movie $movie): void
    {
        $entity = $this->findEntityOrCreate($movie);
        $this->syncToEntity($movie, $entity);
        $this->syncMovieVideos($movie, $entity);
        $this->entityManager->persist($entity);
    }

    public function saveMetadata(Movie $movie): void
    {
        $entity = $this->entityManager->find(MovieEntity::class, $movie->getId());
        if ($entity === null) {
            throw new \LogicException('Cannot update metadata for a movie that has not been persisted.');
        }

        $this->syncMetadataToEntity($movie, $entity);
        $this->entityManager->flush();
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }

    public function findByUuid(Uuid $uuid): ?Movie
    {
        $entity = $this->entityManager
            ->getRepository(MovieEntity::class)
            ->find($uuid);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findByPublicId(PublicId $publicId): ?Movie
    {
        $entity = $this->entityManager
            ->getRepository(MovieEntity::class)
            ->findOneBy(['publicId' => $publicId]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    /**
     * @return Movie[]
     */
    public function findByLibrary(Uuid $libraryId): array
    {
        $entities = $this->entityManager
            ->getRepository(MovieEntity::class)
            ->findBy(['library' => $libraryId], ['title' => 'ASC']);

        return array_map(fn(MovieEntity $entity) => $this->toDomain($entity), $entities);
    }

    public function findByPlacementFolder(Uuid $libraryId, string $folderIdentity): ?Movie
    {
        // DQL has no JSON path extraction for the stored parse.
        $id = $this->entityManager->getConnection()->fetchOne(
            <<<'SQL'
                SELECT placement.movie_id
                FROM video_placements placement
                WHERE placement.library_id = :library
                  AND placement.movie_id IS NOT NULL
                  AND CASE
                      WHEN placement.raw_parse->>'folderPath' = '' THEN placement.relative_path
                      ELSE placement.raw_parse->>'folderPath'
                  END = :folder
                ORDER BY placement.id
                LIMIT 1
                SQL,
            ['library' => $libraryId->toString(), 'folder' => $folderIdentity],
        );
        if (!is_string($id)) {
            return null;
        }
        $entity = $this->entityManager->createQueryBuilder()
            ->select('movie')
            ->from(MovieEntity::class, 'movie')
            ->where('movie.id = :id')
            ->setParameter('id', Uuid::fromString($id), 'uuid')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $entity === null ? null : $this->toDomain($entity);
    }

    public function addVideoForImport(Uuid $movieId, Uuid $videoId): void
    {
        $movie = $this->entityManager->find(MovieEntity::class, $movieId);
        $video = $this->entityManager->find(VideoEntity::class, $videoId);
        if ($movie === null || $video === null) {
            throw new \LogicException('A feature version requires a stored movie and video.');
        }
        $links = $this->entityManager->getRepository(MovieVideoEntity::class);
        if ($links->findOneBy(['movie' => $movieId, 'video' => $videoId]) !== null) {
            return;
        }
        $this->entityManager->persist(new MovieVideoEntity(
            $movie,
            $video,
            $links->count(['movie' => $movieId]),
        ));
    }

    public function removeVideoForImport(Uuid $movieId, Uuid $videoId): void
    {
        $links = $this->entityManager->getRepository(MovieVideoEntity::class)
            ->findBy(['movie' => $movieId, 'video' => $videoId]);
        foreach ($links as $link) {
            $this->entityManager->remove($link);
        }
    }

    public function findByTitleAndLibrary(string $title, Uuid $libraryId): ?Movie
    {
        $entity = $this->entityManager
            ->getRepository(MovieEntity::class)
            ->findOneBy(['title' => $title, 'library' => $libraryId]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findByTmdbId(?int $tmdbId): ?Movie
    {
        if ($tmdbId === null) {
            return null;
        }

        $entity = $this->entityManager
            ->getRepository(MovieEntity::class)
            ->findOneBy(['tmdbId' => $tmdbId]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function search(SearchOptions $options): SearchResult
    {
        return $this->searchVisible($options, LibraryReadScope::unrestricted());
    }

    public function searchVisible(SearchOptions $options, LibraryReadScope $scope): SearchResult
    {
        if (!$options->hasQuery()) {
            $qb = $this->visibleQuery($scope);
            $total = (int) (clone $qb)->select('COUNT(visible.id)')->getQuery()->getSingleScalarResult();
            $entities = $qb
                ->orderBy('visible.title', 'ASC')
                ->addOrderBy('visible.id', 'ASC')
                ->setMaxResults($options
                ->getLimit())
                ->setFirstResult($options
                ->getOffset())
                ->getQuery()
                ->getResult();
            $movies = array_map(fn(MovieEntity $entity) => $this->toDomain($entity), $entities);

            return SearchResult::create($movies, $total);
        }

        $predicate = CatalogReadScopeQuery::native($scope, 'movie', $options);
        $result = $this->buildScopedScoredQuery(
            $options,
            $this->entityManager,
            MovieEntity::class,
            'movies',
            'title',
            $predicate['predicate'],
            $predicate['parameters'],
            $predicate['types'],
        );

        $movies = array_map(fn(MovieEntity $entity) => $this->toDomain($entity), $result['entities']);

        return SearchResult::create($movies, $result['total'], $result['highestScore']);
    }

    public function count(): int
    {
        return (int) $this->entityManager
            ->getRepository(MovieEntity::class)
            ->count([]);
    }

    public function delete(Movie $movie): void
    {
        $entity = $this->entityManager
            ->getRepository(MovieEntity::class)
            ->find($movie->getId());

        if ($entity === null) {
            return;
        }

        $this->entityManager->remove($entity);
        $this->entityManager->flush();

        // The database deletes the movie's video and person credit links (ON DELETE CASCADE). A link this manager
        // still holds would point at the deleted movie and fail the next flush that writes anything.
        foreach ([MovieVideoEntity::class, MoviePersonCreditEntity::class, MoviePersonCreditStateEntity::class] as $linkClass) {
            foreach ($this->entityManager->getUnitOfWork()->getIdentityMap()[$linkClass] ?? [] as $link) {
                if ($link->getMovie() === $entity) {
                    $this->entityManager->detach($link);
                }
            }
        }
    }

    // --- Internal ---

    private function findEntityOrCreate(Movie $movie): MovieEntity
    {
        $existing = $this->entityManager
            ->getRepository(MovieEntity::class)
            ->find($movie->getId());

        if ($existing !== null) {
            return $existing;
        }

        $libraryEntity = $this->entityManager->getRepository(LibraryEntity::class)->find($movie->getLibraryId());
        if ($libraryEntity === null) {
            throw new \RuntimeException('Library not found: ' . $movie->getLibraryId()->toString());
        }

        return new MovieEntity(
            $movie->getPublicId(),
            $libraryEntity,
            $movie->getTitle(),
            id: $movie->getId(),
        );
    }

    /** @param list<MovieVideoEntity>|null $junctionRows */
    private function toDomain(MovieEntity $entity, ?array $junctionRows = null): Movie
    {
        $videoIds = [];
        $videoPublicIds = [];
        $junctionRows ??= $this->entityManager
            ->getRepository(MovieVideoEntity::class)
            ->findBy(['movie' => $entity->getId()], ['sortOrder' => 'ASC', 'id' => 'ASC']);

        foreach ($junctionRows as $junction) {
            $videoIds[] = $junction->getVideo()->getId()->toString();
            $videoPublicIds[] = $junction->getVideo()->getPublicId()->toString();
        }

        return Movie::reconstitute(new MovieState(
            id: $entity->getId(),
            publicId: $entity->getPublicId(),
            libraryId: $entity->getLibrary()->getId(),
            title: $entity->getTitle(),
            year: $entity->getYear(),
            summary: $entity->getSummary(),
            tmdbId: $entity->getTmdbId(),
            imdbId: $entity->getImdbId(),
            overview: $entity->getOverview(),
            tagline: $entity->getTagline(),
            posterUrl: $entity->getPosterUrl(),
            backdropUrl: $entity->getBackdropUrl(),
            runtime: $entity->getRuntime(),
            rating: $entity->getRating(),
            originalLanguage: $entity->getOriginalLanguage(),
            tmdbCollectionId: $entity->getTmdbCollectionId(),
            collectionName: $entity->getCollectionName(),
            videoIds: $videoIds,
            createdAt: $entity->getCreatedAt(),
            updatedAt: $entity->getUpdatedAt(),
            videoPublicIds: $videoPublicIds,
            lockedFields: $entity->getLockedFields(),
        ));
    }

    private function syncToEntity(Movie $movie, MovieEntity $entity): void
    {
        $entity->setTitle($movie->getTitle());
        $entity->setYear($movie->getYear());
        $entity->setSummary($movie->getSummary());
        $entity->setLockedFields($movie->getLockedFields());
        $this->syncMetadataToEntity($movie, $entity);
    }

    private function syncMetadataToEntity(Movie $movie, MovieEntity $entity): void
    {
        $entity->setTmdbId($movie->getTmdbId());
        $entity->setImdbId($movie->getImdbId());
        $entity->setOverview($movie->getOverview());
        $entity->setTagline($movie->getTagline());
        $entity->setPosterUrl($movie->getPosterUrl());
        $entity->setBackdropUrl($movie->getBackdropUrl());
        $entity->setRuntime($movie->getRuntime());
        $entity->setRating($movie->getRating());
        $entity->setOriginalLanguage($movie->getOriginalLanguage());
        $entity->setTmdbCollectionId($movie->getTmdbCollectionId());
        $entity->setCollectionName($movie->getCollectionName());
    }

    private function syncMovieVideos(Movie $movie, MovieEntity $movieEntity): void
    {
        $desiredVideoIds = $movie->getVideoIds();
        $existingJunctions = $this->entityManager
            ->getRepository(MovieVideoEntity::class)
            ->findBy(['movie' => $movieEntity->getId()]);

        $existingVideoIdMap = [];
        foreach ($existingJunctions as $junction) {
            $existingVideoIdMap[$junction->getVideo()->getId()->toString()] = $junction;
        }

        // Remove junctions for videos no longer in the movie
        foreach ($existingVideoIdMap as $videoIdStr => $junction) {
            if (!in_array($videoIdStr, $desiredVideoIds, true)) {
                $this->entityManager->remove($junction);
            }
        }

        // Add new junctions
        $sortOrder = 0;
        foreach ($desiredVideoIds as $videoIdStr) {
            if (!isset($existingVideoIdMap[$videoIdStr])) {
                $videoEntity = $this->entityManager
                    ->getRepository(VideoEntity::class)
                    ->find(Uuid::fromString($videoIdStr));

                if ($videoEntity !== null) {
                    $this->entityManager->persist(new MovieVideoEntity($movieEntity, $videoEntity, $sortOrder));
                }
            }
            $sortOrder++;
        }
    }
}
