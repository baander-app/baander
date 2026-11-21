<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Repository;

use App\Catalog\Domain\Model\TvSeries;
use App\Catalog\Domain\Model\TvEpisode;
use App\Catalog\Application\DTO\TvTitleRecord;
use App\Catalog\Application\Port\TvCatalogPortInterface;
use App\Catalog\Infrastructure\Doctrine\Entity\TvEpisodeEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\TvSeasonEntity;
use App\Catalog\Infrastructure\Doctrine\Entity\TvSeriesEntity;
use App\Catalog\Infrastructure\Doctrine\ImportIdentity\ImportIdentityLock;
use App\Library\Infrastructure\Doctrine\Entity\LibraryEntity;
use App\Metadata\Application\DTO\TvTitleMetadata;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;

final readonly class TvCatalogRepository implements TvCatalogPortInterface
{
    public function __construct(private EntityManagerInterface $manager) {}

    public function registerSeries(Uuid $libraryId, TvTitleMetadata $metadata): TvTitleRecord
    {
        ImportIdentityLock::acquire($this->manager->getConnection(), [
            'catalog-tv-series:' . $libraryId->toString() . ':' . $metadata->tmdbId,
        ]);
        $series = $this->manager->createQueryBuilder()
            ->select('series')
            ->from(TvSeriesEntity::class, 'series')
            ->where('IDENTITY(series.library) = :library')
            ->andWhere('series.tmdbId = :tmdbId')
            ->setParameter('library', $libraryId, 'uuid')
            ->setParameter('tmdbId', $metadata->tmdbId)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
        if (!$series instanceof TvSeriesEntity) {
            $library = $this->manager->find(LibraryEntity::class, $libraryId);
            if ($library === null) {
                throw new NotFoundException('TV library was removed before registration.');
            }
            $model = TvSeries::register($libraryId, $metadata->tmdbId, $metadata->name);
            $series = new TvSeriesEntity($library, $model->getState());
            $this->manager->persist($series);
        }
        $model = TvSeries::reconstitute($series->state());
        $model->updateMetadata($metadata->name, $metadata->overview, $metadata->imageUrl);
        $series->apply($model->getState());
        $this->manager->flush();

        return $this->seriesRecord($series);
    }

    public function registerEpisode(TvTitleRecord $series, int $seasonNumber, int $episodeNumber, TvTitleMetadata $metadata): TvTitleRecord
    {
        ImportIdentityLock::acquire($this->manager->getConnection(), [
            'catalog-tv-episode:' . $series->id->toString() . ':' . $seasonNumber . ':' . $episodeNumber,
            'catalog-tv-season:' . $series->id->toString() . ':' . $seasonNumber,
        ]);
        $parent = $this->manager->find(TvSeriesEntity::class, $series->id);
        if ($parent === null) {
            throw new NotFoundException('TV series was removed before episode registration.');
        }
        $season = $this->manager->getRepository(TvSeasonEntity::class)->findOneBy([
            'series' => $parent,
            'seasonNumber' => $seasonNumber,
        ]);
        if ($season === null) {
            $season = new TvSeasonEntity($parent, $seasonNumber);
            $this->manager->persist($season);
        }
        $episode = $this->manager->getRepository(TvEpisodeEntity::class)->findOneBy([
            'season' => $season,
            'episodeNumber' => $episodeNumber,
        ]);
        if ($episode === null) {
            $model = TvEpisode::register($season->id, $episodeNumber, $metadata->tmdbId, $metadata->name);
            $episode = new TvEpisodeEntity($season, $model->getState());
            $this->manager->persist($episode);
        } else {
            $this->manager->refresh($episode);
        }
        $model = TvEpisode::reconstitute($episode->state());
        $model->updateMetadata(
            $metadata->tmdbId,
            $metadata->name,
            $metadata->overview,
            $metadata->imageUrl,
            $metadata->airDate === null ? null : new DateTimeImmutable($metadata->airDate),
        );
        $episode->apply($model->getState());
        $this->manager->flush();

        return $this->episodeRecord($episode);
    }

    public function findSeries(PublicId $publicId): ?TvTitleRecord
    {
        $series = $this->manager->getRepository(TvSeriesEntity::class)->findOneBy(['publicId' => $publicId]);

        return $series === null ? null : $this->seriesRecord($series);
    }

    public function seriesByLibraryAfter(Uuid $libraryId, ?Uuid $after = null, int $limit = 100): array
    {
        $query = $this->manager->createQueryBuilder()
            ->select('series', 'library')
            ->from(TvSeriesEntity::class, 'series')
            ->join('series.library', 'library')
            ->where('library.id = :library')
            ->setParameter('library', $libraryId, 'uuid')
            ->orderBy('series.id', 'ASC')
            ->setMaxResults(max(1, min($limit, 100)));
        if ($after !== null) {
            $query->andWhere('series.id > :after')->setParameter('after', $after, 'uuid');
        }

        return array_map($this->seriesRecord(...), $query->getQuery()->getResult());
    }

    public function episodesBySeriesAfter(Uuid $seriesId, ?Uuid $after = null, int $limit = 100): array
    {
        $query = $this->manager->createQueryBuilder()
            ->select('episode', 'season', 'series', 'library')
            ->from(TvEpisodeEntity::class, 'episode')
            ->join('episode.season', 'season')
            ->join('season.series', 'series')
            ->join('series.library', 'library')
            ->where('series.id = :series')
            ->setParameter('series', $seriesId, 'uuid')
            ->orderBy('episode.id', 'ASC')
            ->setMaxResults(max(1, min($limit, 100)));
        if ($after !== null) {
            $query->andWhere('episode.id > :after')->setParameter('after', $after, 'uuid');
        }

        return array_map($this->episodeRecord(...), $query->getQuery()->getResult());
    }

    private function seriesRecord(TvSeriesEntity $series): TvTitleRecord
    {
        return new TvTitleRecord(
            id: $series->id,
            publicId: $series->publicId,
            libraryId: $series->library->getId(),
            seriesTmdbId: $series->tmdbId,
            name: $series->name,
        );
    }

    private function episodeRecord(TvEpisodeEntity $episode): TvTitleRecord
    {
        return new TvTitleRecord(
            id: $episode->id,
            publicId: $episode->publicId,
            libraryId: $episode->season->series->library->getId(),
            seriesTmdbId: $episode->season->series->tmdbId,
            name: $episode->name,
            seasonNumber: $episode->season->seasonNumber,
            episodeNumber: $episode->episodeNumber,
        );
    }
}
