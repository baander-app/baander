<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\VideoMatch;

use App\Catalog\Application\Port\VideoAvailabilityPortInterface;
use App\Catalog\Application\Port\VideoMatchTargetLoaderInterface;
use App\Catalog\Application\Service\VideoMatch\VideoMatchTarget;
use App\Catalog\Domain\Model\Movie;
use App\Catalog\Domain\Model\TvSeries;
use App\Catalog\Domain\Model\VideoMatchState;
use App\Catalog\Domain\Model\VideoPlacement;
use App\Catalog\Domain\Repository\MovieRepositoryInterface;
use App\Catalog\Domain\Repository\TvCatalogRepositoryInterface;
use App\Catalog\Domain\Service\VideoName\MovieNameParser;
use App\Catalog\Domain\Service\VideoName\SidecarName;
use App\Catalog\Domain\ValueObject\VideoName\SidecarKind;
use App\Catalog\Infrastructure\Doctrine\Entity\VideoPlacementEntity;
use App\Library\Application\Port\VideoSidecarIndexInterface;
use App\Metadata\Application\DTO\Video\VideoKind;
use App\Metadata\Application\DTO\Video\VideoRequest;
use App\Metadata\Application\DTO\Video\VideoSidecar;
use App\Metadata\Application\DTO\Video\VideoSidecarKind;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

final readonly class VideoMatchTargetLoader implements VideoMatchTargetLoaderInterface
{
    public function __construct(
        private EntityManagerInterface $manager,
        private MovieRepositoryInterface $movies,
        private TvCatalogRepositoryInterface $catalog,
        private VideoSidecarIndexInterface $sidecars,
        private SidecarName $sidecarNames,
        private MovieNameParser $movieNames,
        private VideoAvailabilityPortInterface $availability,
    ) {}

    public function load(VideoMatchState $state): ?VideoMatchTarget
    {
        if ($state->seriesId !== null) {
            $title = $this->catalog->findSeriesByUuid($state->seriesId);
        } else {
            $title = $state->movieId === null ? null : $this->movies->findByUuid($state->movieId);
        }

        return $title === null ? null : $this->forTitle($title);
    }

    public function forObservations(Uuid $libraryId, array $relativePaths): array
    {
        if ($relativePaths === []) {
            return [];
        }

        $titles = [];
        $placements = $this->manager->createQueryBuilder()
            ->select('DISTINCT IDENTITY(p.series) AS seriesId', 'IDENTITY(p.movie) AS movieId')
            ->from(VideoPlacementEntity::class, 'p')
            ->where('p.library = :library AND p.relativePath IN (:paths)')
            ->setParameter('library', $libraryId, 'uuid')
            ->setParameter('paths', $relativePaths, ArrayParameterType::STRING)
            ->getQuery()
            ->getArrayResult();
        foreach ($placements as $placement) {
            $seriesId = $placement['seriesId'];
            $movieId = $placement['movieId'];
            $title = $seriesId !== null
                ? $this->catalog->findSeriesByUuid(Uuid::fromString((string) $seriesId))
                : $this->movies->findByUuid(Uuid::fromString((string) $movieId));
            if ($title !== null) {
                $titles[$title->getId()->toString()] = $title;
            }
        }

        foreach ($this->sidecarOwners($libraryId, $relativePaths) as $title) {
            $titles[$title->getId()->toString()] = $title;
        }

        return array_values(array_map($this->forTitle(...), $titles));
    }

    /**
     * @param list<string> $paths
     * @return iterable<Movie|TvSeries>
     */
    private function sidecarOwners(Uuid $libraryId, array $paths): iterable
    {
        $movieFolders = [];
        $seriesFolders = [];
        $rootStems = [];
        foreach ($paths as $path) {
            $sidecar = $this->sidecarNames->parse($path);
            if ($sidecar === null) {
                continue;
            }
            $directory = dirname($path);
            if ($directory === '.') {
                if ($sidecar->kind === SidecarKind::VideoNfo || $sidecar->kind === SidecarKind::MovieNfo) {
                    $rootStems[] = pathinfo($path, PATHINFO_FILENAME);
                }

                continue;
            }
            if ($sidecar->kind !== SidecarKind::MovieNfo) {
                $seriesFolders[explode('/', $directory, 2)[0]] = true;
            }
            if ($sidecar->kind !== SidecarKind::SeriesNfo) {
                $movieFolders[$this->movieNames->parse($path)->folderPath] = true;
            }
        }

        foreach (array_keys($seriesFolders) as $folder) {
            $series = $this->catalog->findSeriesByFolder($libraryId, (string) $folder);
            if ($series !== null) {
                yield $series;
            }
        }
        foreach (array_keys($movieFolders) as $folder) {
            $movie = $this->movies->findByPlacementFolder($libraryId, (string) $folder);
            if ($movie !== null) {
                yield $movie;
            }
        }
        foreach ($this->rootMovieIds($libraryId, $rootStems) as $id) {
            $movie = $this->movies->findByUuid($id);
            if ($movie !== null) {
                yield $movie;
            }
        }
    }

    /**
     * @param list<string> $stems
     * @return list<Uuid>
     */
    private function rootMovieIds(Uuid $libraryId, array $stems): array
    {
        if ($stems === []) {
            return [];
        }

        // DQL cannot compare a path without its last extension.
        $ids = $this->manager->getConnection()->fetchFirstColumn(
            <<<'SQL'
                SELECT DISTINCT p.movie_id
                FROM video_placements p
                WHERE p.library_id = :library AND p.movie_id IS NOT NULL
                  AND strpos(p.relative_path, '/') = 0
                  AND regexp_replace(p.relative_path, '\.[^.]*$', '') IN (:stems)
                ORDER BY p.movie_id
                SQL,
            ['library' => $libraryId->toString(), 'stems' => $stems],
            ['stems' => ArrayParameterType::STRING],
        );

        return array_map(Uuid::fromString(...), $ids);
    }

    public function forTitle(Movie|TvSeries $title): VideoMatchTarget
    {
        $series = $title instanceof TvSeries;
        $id = $title->getId();
        $libraryId = $title->getLibraryId();
        $placements = $series ? $this->catalog->placementsBySeries($id) : $this->catalog->placementsByMovie($id);
        $folder = $series ? $title->getFolderPath() : $this->movieFolder($libraryId, $placements);
        $externalIds = $title->getExternalIds();
        $matchedProvider = $title->getMatchedProvider();
        $providerId = $title->getProviderId();
        if ($matchedProvider !== null && $providerId !== null) {
            $externalIds[$matchedProvider] = $providerId;
        }
        if (!$series && $title->getTmdbId() !== null) {
            $externalIds['tmdb'] ??= (string) $title->getTmdbId();
        }
        if (!$series && $title->getImdbId() !== null) {
            $externalIds['imdb'] ??= $title->getImdbId();
        }
        $externalIds += self::taggedIds($placements);

        return new VideoMatchTarget(
            $id,
            new VideoRequest(
                kind: $series ? VideoKind::Series : VideoKind::Movie,
                title: $series ? $title->getName() : $title->getTitle(),
                year: $title->getYear(),
                externalIds: $externalIds,
                ordering: $series ? $title->getOrdering() : 'aired',
                libraryId: $libraryId,
                sidecars: $folder === null ? [] : $this->classify($libraryId, $folder, $placements, $series),
                titleId: $id,
            ),
            array_map(static fn (VideoPlacement $placement): array => $placement->getRawParse(), $placements),
            $matchedProvider !== null || (!$series && $title->getTmdbId() !== null),
        );
    }

    /** @param list<VideoPlacement> $placements */
    private function movieFolder(Uuid $libraryId, array $placements): ?string
    {
        $features = [];
        foreach ($placements as $placement) {
            if ($placement->getExtraKind() === null) {
                $features[] = $placement;
            }
        }
        $anchors = $features === [] ? $placements : $features;
        if ($anchors === []) {
            return null;
        }
        $folders = array_map($this->moviePlacementFolder(...), $anchors);
        if (count(array_unique($folders)) === 1) {
            return $folders[0];
        }

        $videoIds = array_map(static fn (VideoPlacement $placement): Uuid => $placement->getVideoId(), $anchors);
        $available = $this->availability->forVideos($videoIds, LibraryReadScope::restricted([$libraryId]));
        $anchors = array_values(array_filter(
            $anchors,
            static fn (VideoPlacement $placement): bool => $available[$placement->getVideoId()->toString()] ?? false,
        )) ?: $anchors;
        $latest = $anchors[0];
        foreach ($anchors as $placement) {
            if ($placement->getState()->createdAt >= $latest->getState()->createdAt) {
                $latest = $placement;
            }
        }

        return $this->moviePlacementFolder($latest);
    }

    private function moviePlacementFolder(VideoPlacement $anchor): string
    {
        $folder = $anchor->getRawParse()['folderPath'] ?? null;

        return is_string($folder) ? $folder : $this->movieNames->parse($anchor->getRelativePath())->folderPath;
    }

    /**
     * @param list<VideoPlacement> $placements
     * @return list<VideoSidecar>
     */
    private function classify(Uuid $libraryId, string $folder, array $placements, bool $series): array
    {
        $videos = [];
        foreach ($placements as $placement) {
            if ($placement->getExtraKind() !== null) {
                continue;
            }
            $path = $placement->getRelativePath();
            $stem = self::pathStem($path);
            if (!isset($videos[$stem]) || $placement->getState()->createdAt >= $videos[$stem]->getState()->createdAt) {
                $videos[$stem] = $placement;
            }
        }

        $sidecars = [];
        foreach ($this->sidecars->forFolder($libraryId, $folder) as $row) {
            $relativePath = $row['relativePath'];
            $parsed = $this->sidecarNames->parse($relativePath);
            if ($parsed === null) {
                continue;
            }
            $kind = VideoSidecarKind::from($parsed->kind->value);
            $placement = $videos[self::pathStem($relativePath)] ?? null;
            if (!$series && $folder === '') {
                if ($placement === null || !in_array($kind, [VideoSidecarKind::MovieNfo, VideoSidecarKind::VideoNfo], true)) {
                    continue;
                }
                $kind = VideoSidecarKind::MovieNfo;
            }
            if ($series && (($kind === VideoSidecarKind::SeriesNfo && dirname($relativePath) !== $folder)
                || ($kind === VideoSidecarKind::VideoNfo && $placement === null))) {
                continue;
            }
            if (!$series && $folder !== '' && $kind === VideoSidecarKind::MovieNfo && dirname($relativePath) !== $folder) {
                continue;
            }

            $sidecars[] = new VideoSidecar($kind, $row['path'], $parsed->seasonNumber, $parsed->videoStem, $placement?->getVideoId());
        }

        return $sidecars;
    }

    private static function pathStem(string $path): string
    {
        return dirname($path) . '/' . pathinfo($path, PATHINFO_FILENAME);
    }

    /**
     * @param list<VideoPlacement> $placements
     * @return array<string, string>
     */
    private static function taggedIds(array $placements): array
    {
        $ids = [];
        $conflicts = [];
        foreach ($placements as $placement) {
            $tags = $placement->getRawParse()['providerTags'] ?? null;
            if ($placement->getExtraKind() !== null || !is_array($tags)) {
                continue;
            }
            foreach ($tags as $tag) {
                $provider = is_array($tag) ? ($tag['provider'] ?? null) : null;
                $id = is_array($tag) ? ($tag['id'] ?? null) : null;
                if (!is_string($provider) || !is_string($id)) {
                    continue;
                }
                if (isset($ids[$provider]) && $ids[$provider] !== $id) {
                    $conflicts[$provider] = true;
                }
                $ids[$provider] = $id;
            }
        }

        return array_diff_key($ids, $conflicts);
    }
}
