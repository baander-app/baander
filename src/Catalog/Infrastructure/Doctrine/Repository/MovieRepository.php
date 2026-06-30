<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Repository;

use App\Catalog\Domain\Model\Movie;
use App\Catalog\Domain\Model\MovieState;
use App\Catalog\Domain\Repository\MovieRepositoryInterface;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieVideoEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\VideoEntity;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\Model\SearchResult;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Doctrine\Repository\PgroongaSearchTrait;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Pure domain repository for movies.
 */
final class MovieRepository implements MovieRepositoryInterface
{
    use PgroongaSearchTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
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

    public function findByLibrary(Uuid $libraryId): array
    {
        $entities = $this->entityManager
            ->getRepository(MovieEntity::class)
            ->findBy(['library' => $libraryId], ['title' => 'ASC']);

        return array_map(fn (MovieEntity $entity) => $this->toDomain($entity), $entities);
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
        if (!$options->hasQuery()) {
            $repo = $this->entityManager->getRepository(MovieEntity::class);
            $total = (int) $repo->count([]);
            $entities = $repo->findBy([], ['title' => 'ASC'], $options->getLimit(), $options->getOffset());
            $movies = array_map(fn (MovieEntity $entity) => $this->toDomain($entity), $entities);

            return SearchResult::create($movies, $total);
        }

        $result = $this->buildScoredQuery(
            $options,
            $this->entityManager,
            MovieEntity::class,
            'movies',
            'title',
        );

        $movies = array_map(fn (MovieEntity $entity) => $this->toDomain($entity), $result['entities']);

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

        if ($entity !== null) {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
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

    private function toDomain(MovieEntity $entity): Movie
    {
        $videoIds = [];
        $junctionRows = $this->entityManager
            ->getRepository(MovieVideoEntity::class)
            ->findBy(['movie' => $entity->getId()]);

        foreach ($junctionRows as $junction) {
            $videoIds[] = $junction->getVideo()->getId()->toString();
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
        ));
    }

    private function syncToEntity(Movie $movie, MovieEntity $entity): void
    {
        $entity->setTitle($movie->getTitle());
        $entity->setYear($movie->getYear());
        $entity->setSummary($movie->getSummary());
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
