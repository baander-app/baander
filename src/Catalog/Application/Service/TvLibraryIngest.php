<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service;

use App\Catalog\Domain\Model\TvEpisode;
use App\Catalog\Domain\Model\TvSeason;
use App\Catalog\Domain\Model\TvSeries;
use App\Catalog\Domain\Model\Video;
use App\Catalog\Domain\Model\VideoPlacement;
use App\Catalog\Domain\Repository\TvCatalogRepositoryInterface;
use App\Catalog\Domain\Repository\VideoRepositoryInterface;
use App\Catalog\Domain\Service\VideoName\EpisodeNameParser;
use App\Catalog\Domain\ValueObject\VideoName\EpisodeName;
use App\Library\Application\Message\DiscoveredFile;
use App\Library\Application\Port\LibraryMediaFilesInterface;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use LogicException;

final readonly class TvLibraryIngest
{
    public function __construct(
        private TvCatalogRepositoryInterface $catalog,
        private VideoRepositoryInterface $videos,
        private LibraryMediaFilesInterface $mediaFiles,
        private EpisodeNameParser $parser,
    ) {
    }

    public function accepts(DiscoveredFile $file): bool
    {
        return !$this->parser->parse($file->relativePath)->sample;
    }

    /**
     * The folder lock covers its series, seasons and episodes. The hash lock also serializes
     * videos whose observations are under different folders or libraries.
     *
     * @param list<DiscoveredFile> $files
     * @return array<string, Uuid>
     */
    public function prepareBatch(Uuid $library, array $files): array
    {
        $keys = [];
        foreach ($files as $file) {
            $folder = $this->folder($this->parser->parse($file->relativePath));
            $keys[] = 'video-hash:' . $file->hash;
            $keys[] = 'video-tv-folder:' . $library->toString() . ':' . $folder;
        }
        $this->catalog->lockImportIdentities($keys);

        $known = [];
        foreach ($files as $file) {
            $video = $this->videos->findByHash($file->hash);
            if ($video === null) {
                continue;
            }
            $placement = $this->catalog->findPlacement($library, $video->getId());
            if ($placement === null || $this->isDuplicate($placement, $file)) {
                continue;
            }
            $seriesId = $placement->getSeriesId();
            $series = $seriesId === null ? null : $this->catalog->findSeriesByUuid($seriesId);
            if ($series !== null) {
                $known[$this->folder($this->parser->parse($file->relativePath))] = $series->getId();
            }
        }

        return $known;
    }

    /** @param array<string, Uuid> $known */
    public function ingestFile(Uuid $library, DiscoveredFile $file, Video $video, array $known): void
    {
        $name = $this->parser->parse($file->relativePath);
        $folder = $this->folder($name);
        $existing = $this->catalog->findPlacement($library, $video->getId());
        $ownerId = $existing?->getSeriesId();
        $series = isset($known[$folder]) ? $this->catalog->findSeriesByUuid($known[$folder]) : null;
        $series ??= ($ownerId === null ? null : $this->catalog->findSeriesByUuid($ownerId))
            ?? $this->catalog->findSeriesByFolder($library, $folder);
        $series = $this->lockPlacementOwners($series, $ownerId);
        if ($existing !== null) {
            $existing = $this->catalog->findPlacement($library, $video->getId());
        }
        if ($existing !== null && $this->isDuplicate($existing, $file)) {
            $this->recordDuplicate($existing, $file);

            return;
        }

        if ($series === null) {
            $series = TvSeries::create($library, $folder, $name->title, $name->year);
            $this->catalog->saveSeries($series);
            $this->catalog->flush();
        } elseif ($series->getFolderPath() !== $folder) {
            $series->relocate($folder);
            $this->catalog->saveSeries($series);
            $this->catalog->flush();
        }

        if ($existing !== null) {
            $this->updateObservation($existing, $file, $name);
        } else {
            $placement = VideoPlacement::create($library, $video->getId(), $file->relativePath, $name->jsonSerialize(), seriesId: $series->getId());
            $previous = $this->replacedAssignment($series, $file);
            if ($previous !== null) {
                $placement->copyAssignmentFrom($previous);
            } elseif ($name->extraKind !== null) {
                $placement->assignExtra($name->extraKind);
            } else {
                $this->placeEpisodes($series, $name, $placement);
            }
            $this->catalog->savePlacement($placement);
        }

        $this->catalog->flush();
    }

    public function flush(): void
    {
        $this->catalog->flush();
    }

    private function lockPlacementOwners(?TvSeries $series, ?Uuid $ownerId): ?TvSeries
    {
        $ids = [];
        foreach ([$ownerId, $series?->getId()] as $id) {
            if ($id !== null) {
                $ids[$id->toString()] = $id;
            }
        }
        ksort($ids, SORT_STRING);
        $locked = [];
        foreach ($ids as $key => $id) {
            $locked[$key] = $this->catalog->lockSeriesForPlacement($id);
        }

        return $series === null ? null : ($locked[$series->getId()->toString()] ?? null);
    }

    private function folder(EpisodeName $name): string
    {
        return $name->seriesFolder !== '' ? $name->seriesFolder : $name->title;
    }

    private function placeEpisodes(TvSeries $series, EpisodeName $name, VideoPlacement $placement): void
    {
        [$episodes, $pending] = $this->episodes($series, $name);
        if ($episodes === []) {
            $placement->review('no_candidates');

            return;
        }
        $episodeIds = array_map(static fn (TvEpisode $episode): Uuid => $episode->getId(), $episodes);
        $route = $this->route($name->jsonSerialize());
        $collision = false;
        foreach ($this->catalog->unassignedPlacementsByEpisodes($series->getId(), $episodeIds) as $other) {
            if ($this->route($other->getRawParse()) === $route) {
                continue;
            }
            $other->review('episode_collision');
            $this->catalog->savePlacement($other);
            $collision = true;
        }
        if ($collision) {
            $placement->review('episode_collision');

            return;
        }
        $placement->assignEpisodes($episodeIds);
        if ($pending) {
            $placement->review('no_candidates');
        }
    }

    /** @return array{list<TvEpisode>, bool} */
    private function episodes(TvSeries $series, EpisodeName $name): array
    {
        $id = $series->getId();
        $matched = $series->getMatchedProvider() !== null;
        if ($name->seasonNumber !== null && $name->episodeNumbers !== []) {
            return $this->numberedEpisodes($id, $name->seasonNumber, $name->episodeNumbers, $matched);
        }
        if ($name->absoluteNumber !== null) {
            $existing = $this->catalog->findEpisodeByAbsoluteNumber($id, $name->absoluteNumber);
            if ($existing !== null) {
                return [[$existing], false];
            }
            if (!$matched) {
                $episode = TvEpisode::create($id, null, null, 'Episode ' . $name->absoluteNumber, absoluteNumber: $name->absoluteNumber);

                return [[$this->saveEpisode($episode)], false];
            }
        } elseif ($name->airDate !== null) {
            $existing = $this->catalog->episodesByAirDate($id, $name->airDate);
            if (count($existing) === 1) {
                return [$existing, false];
            }
            if (!$matched && $existing === []) {
                $episode = TvEpisode::create($id, null, null, $name->airDate, airDate: new DateTimeImmutable($name->airDate));

                return [[$this->saveEpisode($episode)], false];
            }
        }

        return [[], false];
    }

    /**
     * @param list<int> $numbers
     * @return array{list<TvEpisode>, bool}
     */
    private function numberedEpisodes(Uuid $series, int $seasonNumber, array $numbers, bool $matched): array
    {
        $episodes = [];
        $pending = false;
        $season = null;
        foreach ($numbers as $number) {
            if ($number <= 0) {
                continue;
            }
            $episode = $this->catalog->findEpisodeByNumber($series, $seasonNumber, $number);
            if ($episode === null) {
                $season ??= $this->season($series, $seasonNumber);
                $episode = $this->saveEpisode(TvEpisode::create($series, $season->getId(), $number, 'Episode ' . $number));
                $pending = $matched;
            } elseif ($matched && $episode->getProviderId() === null) {
                $pending = true;
            }
            $episodes[] = $episode;
        }

        return [$episodes, $pending];
    }

    private function season(Uuid $series, int $number): TvSeason
    {
        $season = $this->catalog->findSeason($series, $number);
        if ($season === null) {
            $season = TvSeason::create($series, $number);
            $this->catalog->saveSeason($season);
            $this->catalog->flush();
        }

        return $season;
    }

    private function saveEpisode(TvEpisode $episode): TvEpisode
    {
        $this->catalog->saveEpisode($episode);
        $this->catalog->flush();

        return $episode;
    }

    /** @param array<string, mixed> $raw */
    private function route(array $raw): ?string
    {
        return match (true) {
            ($raw['episodeNumbers'] ?? []) !== [] => 'numbered',
            isset($raw['absoluteNumber']) => 'absolute',
            isset($raw['airDate']) => 'date',
            default => null,
        };
    }

    private function isDuplicate(VideoPlacement $placement, DiscoveredFile $file): bool
    {
        $oldPath = $placement->getRelativePath();

        return $oldPath !== $file->relativePath && $this->hasHashAt($placement->getLibraryId(), $oldPath, $file, $file->hash);
    }

    private function recordDuplicate(VideoPlacement $placement, DiscoveredFile $file): void
    {
        $duplicates = $this->availableDuplicates($placement, $file);
        $duplicates[] = $file->relativePath;
        $raw = $placement->getRawParse();
        $raw['duplicatePaths'] = array_values(array_unique($duplicates));
        $placement->observe($placement->getRelativePath(), $raw);
        $placement->review('duplicate_file');
        $this->catalog->savePlacement($placement);
        $this->catalog->flush();
    }

    private function updateObservation(VideoPlacement $placement, DiscoveredFile $file, EpisodeName $name): void
    {
        $raw = $name->jsonSerialize();
        $duplicates = $this->availableDuplicates($placement, $file);
        if ($duplicates !== []) {
            $raw['duplicatePaths'] = $duplicates;
        } elseif ($placement->getReviewReason() === 'duplicate_file') {
            $placement->review(null);
        }
        $placement->observe($file->relativePath, $raw);
        $this->catalog->savePlacement($placement);
    }

    /** @return list<string> */
    private function availableDuplicates(VideoPlacement $placement, DiscoveredFile $file): array
    {
        return array_values(array_filter(
            $this->duplicatePaths($placement),
            fn (string $path): bool => $path !== $file->relativePath
                && $this->hasHashAt($placement->getLibraryId(), $path, $file, $file->hash),
        ));
    }

    /** @return list<string> */
    private function duplicatePaths(VideoPlacement $placement): array
    {
        $paths = $placement->getRawParse()['duplicatePaths'] ?? [];

        return is_array($paths) ? array_values(array_filter($paths, is_string(...))) : [];
    }

    private function replacedAssignment(TvSeries $series, DiscoveredFile $file): ?VideoPlacement
    {
        $latest = $this->catalog->latestAssignedPlacementBySeriesAtPath($series->getId(), $file->relativePath);
        if ($latest === null) {
            return null;
        }
        $oldHash = $this->videos->findByUuid($latest->getVideoId())?->getHash();
        if ($oldHash === null || $oldHash === $file->hash) {
            return null;
        }
        foreach ($this->duplicatePaths($latest) as $path) {
            if ($this->hasHashAt($series->getLibraryId(), $path, $file, $oldHash)) {
                return null;
            }
        }

        return $latest;
    }

    private function hasHashAt(Uuid $library, string $relativePath, DiscoveredFile $reference, string $hash): bool
    {
        $suffix = '/' . $reference->relativePath;
        if (!str_ends_with($reference->absolutePath, $suffix)) {
            throw new LogicException('A discovered video path must match its relative observation.');
        }
        $absolutePath = substr($reference->absolutePath, 0, -strlen($suffix)) . '/' . $relativePath;

        return ($this->mediaFiles->findAvailableHashesByPaths($library, [$absolutePath])[$absolutePath] ?? null) === $hash
            && $this->mediaFiles->markMissingUnavailable($library, [$absolutePath]) === [];
    }
}
