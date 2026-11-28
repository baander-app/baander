<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Repository;

use App\Catalog\Domain\Model\TvEpisode;
use App\Catalog\Domain\Model\TvSeason;
use App\Catalog\Domain\Model\TvSeries;
use App\Catalog\Domain\Model\VideoPlacement;
use App\Catalog\Domain\Repository\TvCatalogRepositoryInterface;
use App\Catalog\Infrastructure\Doctrine\Entity\MovieEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\TvEpisodeEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\TvSeasonEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\TvSeriesEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\VideoEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\VideoPlacementEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\VideoPlacementEpisodeEntity;
use App\Catalog\Infrastructure\Doctrine\ImportIdentity\ImportIdentityLock;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use InvalidArgumentException;

final readonly class TvCatalogRepository implements TvCatalogRepositoryInterface
{
    public function __construct(private EntityManagerInterface $manager) {}

    public function lockImportIdentities(array $keys): void
    {
        ImportIdentityLock::acquire($this->manager->getConnection(), $keys);
    }

    public function findSeriesByUuid(Uuid $id): ?TvSeries
    {
        $entity = $this->one(TvSeriesEntity::class, ['id' => $id]);

        return $entity === null ? null : TvSeries::reconstitute($entity->state());
    }

    public function lockSeriesForPlacement(Uuid $id): ?TvSeries
    {
        $entity = $this->manager->createQueryBuilder()
            ->select('series')
            ->from(TvSeriesEntity::class, 'series')
            ->where('series.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();

        return $entity instanceof TvSeriesEntity ? TvSeries::reconstitute($entity->state()) : null;
    }

    public function findSeriesByPublicId(PublicId $id): ?TvSeries
    {
        $entity = $this->one(TvSeriesEntity::class, ['publicId' => $id]);

        return $entity === null ? null : TvSeries::reconstitute($entity->state());
    }

    public function findSeriesByFolder(Uuid $libraryId, string $folderPath): ?TvSeries
    {
        $entity = $this->one(TvSeriesEntity::class, ['library' => $libraryId, 'folderPath' => $folderPath]);

        return $entity === null ? null : TvSeries::reconstitute($entity->state());
    }

    public function findSeriesByVideo(Uuid $libraryId, Uuid $videoId): ?TvSeries
    {
        $placement = $this->one(VideoPlacementEntity::class, ['library' => $libraryId, 'video' => $videoId]);

        return $placement?->series === null ? null : $this->findSeriesByUuid($placement->series->id);
    }

    public function findSeason(Uuid $seriesId, int $seasonNumber): ?TvSeason
    {
        $entity = $this->one(TvSeasonEntity::class, ['series' => $seriesId, 'seasonNumber' => $seasonNumber]);

        return $entity === null ? null : TvSeason::reconstitute($entity->state());
    }

    public function seasonsBySeries(Uuid $seriesId): array
    {
        /** @var list<TvSeasonEntity> $entities */
        $entities = $this->manager->createQueryBuilder()
            ->select('season')
            ->from(TvSeasonEntity::class, 'season')
            ->where('IDENTITY(season.series) = :series')
            ->setParameter('series', $seriesId, 'uuid')
            ->orderBy('season.seasonNumber', 'ASC')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        return array_map(static fn (TvSeasonEntity $entity): TvSeason => TvSeason::reconstitute($entity->state()), $entities);
    }

    public function findEpisodeByUuid(Uuid $id): ?TvEpisode
    {
        $entity = $this->one(TvEpisodeEntity::class, ['id' => $id]);

        return $entity === null ? null : TvEpisode::reconstitute($entity->state());
    }

    public function findEpisodeByNumber(Uuid $seriesId, int $seasonNumber, int $episodeNumber): ?TvEpisode
    {
        $entity = $this->manager->createQueryBuilder()
            ->select('episode')
            ->from(TvEpisodeEntity::class, 'episode')
            ->join('episode.season', 'season')
            ->where('IDENTITY(episode.series) = :series')
            ->andWhere('season.seasonNumber = :season')
            ->andWhere('episode.episodeNumber = :episode')
            ->andWhere('episode.retired = false')
            ->setParameter('series', $seriesId, 'uuid')
            ->setParameter('season', $seasonNumber)
            ->setParameter('episode', $episodeNumber)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $entity instanceof TvEpisodeEntity ? TvEpisode::reconstitute($entity->state()) : null;
    }

    public function findEpisodeByAbsoluteNumber(Uuid $seriesId, int $absoluteNumber): ?TvEpisode
    {
        $entities = $this->manager->getRepository(TvEpisodeEntity::class)->findBy([
            'series' => $seriesId,
            'absoluteNumber' => $absoluteNumber,
            'retired' => false,
        ], limit: 2);

        return count($entities) === 1 ? $this->episodeModels($entities)[0] : null;
    }

    public function episodesByAirDate(Uuid $seriesId, string $airDate): array
    {
        $entities = $this->manager->getRepository(TvEpisodeEntity::class)->findBy([
            'series' => $seriesId,
            'airDate' => new DateTimeImmutable($airDate),
            'retired' => false,
        ], ['id' => 'ASC']);

        return $this->episodeModels($entities);
    }

    public function episodesBySeries(Uuid $seriesId): array
    {
        /** @var list<TvEpisodeEntity> $entities */
        $entities = $this->manager->createQueryBuilder()
            ->select('episode')
            ->from(TvEpisodeEntity::class, 'episode')
            ->where('IDENTITY(episode.series) = :series')
            ->setParameter('series', $seriesId, 'uuid')
            ->orderBy('episode.id', 'ASC')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        return array_map(static fn (TvEpisodeEntity $entity): TvEpisode => TvEpisode::reconstitute($entity->state()), $entities);
    }

    public function findPlacement(Uuid $libraryId, Uuid $videoId): ?VideoPlacement
    {
        $entity = $this->placementQuery()
            ->where('IDENTITY(placement.library) = :library')
            ->andWhere('IDENTITY(placement.video) = :video')
            ->setParameter('library', $libraryId, 'uuid')
            ->setParameter('video', $videoId, 'uuid')
            ->orderBy('link.sortOrder', 'ASC')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $entity instanceof VideoPlacementEntity ? VideoPlacement::reconstitute($entity->state()) : null;
    }

    public function placementsBySeries(Uuid $seriesId): array
    {
        /** @var list<VideoPlacementEntity> $entities */
        $entities = $this->placementQuery()
            ->where('IDENTITY(placement.series) = :series')
            ->setParameter('series', $seriesId, 'uuid')
            ->orderBy('placement.sortOrder', 'ASC')
            ->addOrderBy('placement.id', 'ASC')
            ->addOrderBy('link.sortOrder', 'ASC')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        return array_map(static fn (VideoPlacementEntity $entity): VideoPlacement => VideoPlacement::reconstitute($entity->state()), $entities);
    }

    public function placementsByMovie(Uuid $movieId): array
    {
        return $this->placementModels($this->manager->getRepository(VideoPlacementEntity::class)
            ->findBy(['movie' => $movieId], ['sortOrder' => 'ASC', 'id' => 'ASC']));
    }

    public function latestAssignedPlacementBySeriesAtPath(Uuid $seriesId, string $relativePath): ?VideoPlacement
    {
        return $this->latestAssignedPlacementAtPath('series', $seriesId, $relativePath);
    }

    public function latestAssignedPlacementByMovieAtPath(Uuid $movieId, string $relativePath): ?VideoPlacement
    {
        return $this->latestAssignedPlacementAtPath('movie', $movieId, $relativePath);
    }

    public function unassignedPlacementsByEpisodes(Uuid $seriesId, array $episodeIds): array
    {
        if ($episodeIds === []) {
            return [];
        }
        $entities = $this->manager->createQueryBuilder()
            ->select('DISTINCT placement')
            ->from(VideoPlacementEntity::class, 'placement')
            ->join('placement.episodes', 'link')
            ->where('IDENTITY(placement.series) = :series')
            ->andWhere('placement.assigned = false')
            ->andWhere('IDENTITY(link.episode) IN (:episodes)')
            ->setParameter('series', $seriesId, 'uuid')
            ->setParameter('episodes', array_map(static fn (Uuid $id): string => $id->toString(), $episodeIds), ArrayParameterType::STRING)
            ->orderBy('placement.sortOrder', 'ASC')
            ->addOrderBy('placement.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->placementModels($entities);
    }

    public function saveSeries(TvSeries $series): void
    {
        $state = $series->getState();
        $entity = $this->manager->find(TvSeriesEntity::class, $state->id);
        if ($entity === null) {
            $entity = new TvSeriesEntity($this->required(LibraryEntity::class, $state->libraryId), $state);
        } else {
            $entity->apply($state);
        }
        $this->manager->persist($entity);
    }

    public function saveSeason(TvSeason $season): void
    {
        $state = $season->getState();
        $entity = $this->manager->find(TvSeasonEntity::class, $state->id);
        if ($entity === null) {
            $entity = new TvSeasonEntity($this->required(TvSeriesEntity::class, $state->seriesId), $state);
        } else {
            $entity->apply($state);
        }
        $this->manager->persist($entity);
    }

    public function saveEpisode(TvEpisode $episode): void
    {
        $state = $episode->getState();
        $series = $this->required(TvSeriesEntity::class, $state->seriesId);
        $season = $state->seasonId === null ? null : $this->required(TvSeasonEntity::class, $state->seasonId);
        if ($season !== null && !$season->series->id->equals($series->id)) {
            throw new InvalidArgumentException('An episode and its season must belong to the same series.');
        }
        $entity = $this->manager->find(TvEpisodeEntity::class, $state->id);
        if ($entity === null) {
            $entity = new TvEpisodeEntity($series, $season, $state);
        } else {
            $entity->season = $season;
            $entity->apply($state);
        }
        $this->manager->persist($entity);
    }

    public function removeMergedEpisode(TvEpisode $episode): void
    {
        $this->manager->remove($this->required(TvEpisodeEntity::class, $episode->getId()));
    }

    public function savePlacement(VideoPlacement $placement): void
    {
        $state = $placement->getState();
        $entity = $this->manager->find(VideoPlacementEntity::class, $state->id);
        if ($entity === null) {
            $entity = new VideoPlacementEntity(
                $this->required(LibraryEntity::class, $state->libraryId),
                $this->required(VideoEntity::class, $state->videoId),
                $state,
            );
        }
        $entity->series = $state->seriesId === null ? null : $this->required(TvSeriesEntity::class, $state->seriesId);
        $entity->movie = $state->movieId === null ? null : $this->required(MovieEntity::class, $state->movieId);
        $ownerLibrary = $entity->series?->library->getId() ?? $entity->movie?->getLibrary()->getId();
        if ($ownerLibrary === null || !$ownerLibrary->equals($state->libraryId)) {
            throw new InvalidArgumentException('A video placement and its title must belong to the same library.');
        }
        $entity->apply($state);
        $this->syncEpisodes($entity, $state->episodeIds);
        $this->manager->persist($entity);
    }

    public function flush(): void
    {
        $this->manager->flush();
    }

    /** @param list<Uuid> $episodeIds */
    private function syncEpisodes(VideoPlacementEntity $placement, array $episodeIds): void
    {
        $remaining = [];
        foreach ($placement->episodes as $link) {
            $remaining[$link->episode->id->toString()] = $link;
        }
        foreach ($episodeIds as $sortOrder => $episodeId) {
            $episode = $this->required(TvEpisodeEntity::class, $episodeId);
            if ($placement->series === null || !$episode->series->id->equals($placement->series->id)) {
                throw new InvalidArgumentException('A placement can play only episodes of its own series.');
            }
            $key = $episodeId->toString();
            if (isset($remaining[$key])) {
                $remaining[$key]->sortOrder = $sortOrder;
                unset($remaining[$key]);
            } else {
                $placement->episodes->add(new VideoPlacementEpisodeEntity($placement, $episode, $sortOrder));
            }
        }
        foreach ($remaining as $link) {
            $placement->episodes->removeElement($link);
        }
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param array<string, mixed> $criteria
     * @return T|null
     */
    private function one(string $class, array $criteria): ?object
    {
        $entity = $this->manager->getRepository($class)->findOneBy($criteria);
        if ($entity !== null) {
            $this->manager->refresh($entity);
        }

        return $entity;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function required(string $class, Uuid $id): object
    {
        return $this->manager->find($class, $id)
            ?? throw new NotFoundException('The video catalog parent was removed before import.');
    }

    private function latestAssignedPlacementAtPath(string $owner, Uuid $ownerId, string $relativePath): ?VideoPlacement
    {
        $entity = $this->manager->createQueryBuilder()
            ->select('placement')
            ->from(VideoPlacementEntity::class, 'placement')
            ->where('IDENTITY(placement.' . $owner . ') = :owner')
            ->andWhere('placement.relativePath = :path')
            ->andWhere('placement.assigned = true')
            ->setParameter('owner', $ownerId, 'uuid')
            ->setParameter('path', $relativePath)
            ->orderBy('placement.createdAt', 'DESC')
            ->addOrderBy('placement.sortOrder', 'ASC')
            ->addOrderBy('placement.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        if (!$entity instanceof VideoPlacementEntity) {
            return null;
        }
        $this->manager->refresh($entity);

        return VideoPlacement::reconstitute($entity->state());
    }

    /** @param list<TvEpisodeEntity> $entities
     * @return list<TvEpisode>
     */
    private function episodeModels(array $entities): array
    {
        return array_map(function (TvEpisodeEntity $episode): TvEpisode {
            $this->manager->refresh($episode);

            return TvEpisode::reconstitute($episode->state());
        }, $entities);
    }

    /** @param list<VideoPlacementEntity> $entities
     * @return list<VideoPlacement>
     */
    private function placementModels(array $entities): array
    {
        return array_map(function (VideoPlacementEntity $placement): VideoPlacement {
            $this->manager->refresh($placement);

            return VideoPlacement::reconstitute($placement->state());
        }, $entities);
    }

    private function placementQuery(): QueryBuilder
    {
        return $this->manager->createQueryBuilder()
            ->select('placement', 'link')
            ->from(VideoPlacementEntity::class, 'placement')
            ->leftJoin('placement.episodes', 'link');
    }
}
