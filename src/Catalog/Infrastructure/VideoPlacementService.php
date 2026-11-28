<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure;

use App\Activity\Application\DTO\WatchedMarkEpisodeMerge;
use App\Activity\Application\DTO\WatchedMarkVideoMove;
use App\Activity\Application\Port\WatchedMarkMovePortInterface;
use App\Catalog\Application\Service\VideoMatch\EpisodePlacer;
use App\Catalog\Domain\Model\TvEpisode;
use App\Catalog\Domain\Model\TvSeason;
use App\Catalog\Domain\Model\TvSeries;
use App\Catalog\Domain\Model\VideoPlacement;
use App\Catalog\Domain\Repository\MovieRepositoryInterface;
use App\Catalog\Domain\Repository\TvCatalogRepositoryInterface;
use App\Catalog\Domain\ValueObject\VideoName\ExtraKind;
use App\Metadata\Application\DTO\Video\VideoEpisode;
use App\Metadata\Application\DTO\Video\VideoProviderId;
use App\Metadata\Application\DTO\Video\VideoSeason;
use App\Metadata\Application\DTO\Video\VideoTitle;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * @phpstan-type EpisodeCoordinate array{seasonNumber: int, episodeNumber: int, provider: VideoEpisode|null}
 * @phpstan-type PlacementTarget array{providerId: string|null, seasonNumber: int, episodeNumber: int}
 * @phpstan-type PlacementDecision array{targets: list<PlacementTarget>, reviewReason: string|null}
 */
final readonly class VideoPlacementService
{
    public function __construct(
        private TvCatalogRepositoryInterface $catalog,
        private EpisodePlacer $placer,
        private WatchedMarkMovePortInterface $marks,
        private TransactionPortInterface $transaction,
        private MovieRepositoryInterface $movies,
    ) {}

    /** The caller holds the movie row lock in the match transaction. */
    public function resetMovieAssignments(Uuid $movieId): void
    {
        foreach ($this->catalog->placementsByMovie($movieId) as $placement) {
            if (!$placement->isAssigned()) {
                continue;
            }
            $placement->resetAssignment();
            $raw = $placement->getRawParse()['extraKind'] ?? null;
            $placement->applyParsedExtraKind(is_string($raw) ? ExtraKind::tryFrom($raw) : null);
            $placement->review('assignment_reset');
            $this->catalog->savePlacement($placement);
            if ($placement->getExtraKind() === null) {
                $this->movies->addVideoForImport($movieId, $placement->getVideoId());
            } else {
                $this->movies->removeVideoForImport($movieId, $placement->getVideoId());
            }
        }
        $this->catalog->flush();
    }

    /**
     * The caller holds the series row lock in the match transaction. Run this before updating its provider identity.
     * False means the selected ordering is missing and the previous structure is still in use.
     */
    public function apply(
        Uuid $operationId,
        TvSeries $series,
        VideoProviderId $provider,
        VideoTitle $metadata,
        ?VideoTitle $aired = null,
        bool $resetAssignments = false,
    ): bool {
        $ordering = $series->getOrdering();
        if (!$metadata->hasOrdering($ordering)) {
            return false;
        }
        $seriesId = $series->getId();
        $sameEntry = $series->getMatchedProvider() === $provider->value && $series->getProviderId() === $metadata->entry->id;
        $seasons = $this->seasonsByNumber($seriesId);
        $episodes = $this->episodesById($seriesId);
        $placements = $this->catalog->placementsBySeries($seriesId);
        [$existingCoordinates, $nativeIds] = self::existingIdentities($seasons, $episodes, $sameEntry);
        $airedSeasons = $ordering === 'aired' ? $metadata->seasons : $aired?->seasons;
        [$automatic, $resets] = self::automaticPlacements($placements, $resetAssignments);
        $plan = $this->placer->plan($metadata->seasons, $automatic, $nativeIds, $airedSeasons);
        $decisions = $plan['placements'];
        [$coordinates, $providerCoordinates] = self::desiredStructure($metadata, $decisions);
        [$candidates, $absorbable] = self::episodeCandidates(
            $coordinates,
            $providerCoordinates,
            $existingCoordinates,
            $nativeIds,
            $placements,
            $decisions,
        );
        $seasons = $this->addSeasons($seriesId, $seasons, $metadata, $coordinates);
        [$targets, $merges] = self::structureEpisodes($seriesId, $seasons, $coordinates, $episodes, $candidates, $absorbable);
        $assignments = self::placementAssignments($placements, $decisions, $targets, $nativeIds, $providerCoordinates, $merges, $resets);

        $this->saveStructure(
            $seasons,
            $episodes,
            $targets,
            $coordinates,
            $existingCoordinates,
            $assignments,
            $merges,
            $plan['absoluteNumbers'],
            $sameEntry,
        );
        $this->writePlacementChanges($operationId, $placements, $assignments, $decisions, $merges, $resets);
        foreach (array_keys($merges) as $source) {
            $this->catalog->removeMergedEpisode($episodes[$source]);
        }
        $this->catalog->flush();

        return true;
    }

    /**
     * @param list<VideoPlacement> $placements
     * @return array{list<VideoPlacement>, array<string, ExtraKind|null>}
     */
    private static function automaticPlacements(array $placements, bool $resetAssignments): array
    {
        $automatic = [];
        $resets = [];
        foreach ($placements as $placement) {
            if ($placement->isAssigned()) {
                if (!$resetAssignments) {
                    continue;
                }
                $rawExtra = $placement->getRawParse()['extraKind'] ?? null;
                $extra = is_string($rawExtra) ? ExtraKind::tryFrom($rawExtra) : null;
                $resets[$placement->getId()->toString()] = $extra;
                if ($extra === null) {
                    $copy = VideoPlacement::reconstitute(clone $placement->getState());
                    $copy->assignEpisodes([]);
                    $automatic[] = $copy;
                }
            } elseif ($placement->getExtraKind() === null) {
                $automatic[] = $placement;
            }
        }

        return [$automatic, $resets];
    }

    /** @param list<Uuid> $episodeIds */
    public function replaceAssignment(
        Uuid $operationId,
        Uuid $seriesId,
        Uuid $placementId,
        array $episodeIds,
        ?ExtraKind $extra = null,
    ): bool {
        if (($extra === null) === ($episodeIds === [])) {
            throw new InvalidArgumentException('Choose one extra or at least one episode for the placement.');
        }

        return $this->transaction->run(function () use ($operationId, $seriesId, $placementId, $episodeIds, $extra): bool {
            if ($this->catalog->lockSeriesForPlacement($seriesId) === null) {
                return false;
            }
            $placements = $this->catalog->placementsBySeries($seriesId);
            $selected = array_find($placements, static fn (VideoPlacement $placement): bool => $placement->getId()->equals($placementId));
            if ($selected === null) {
                return false;
            }
            $episodes = $this->episodesById($seriesId);
            foreach ($episodeIds as $id) {
                if (!isset($episodes[$id->toString()]) || $episodes[$id->toString()]->isRetired()) {
                    throw new InvalidArgumentException('A placement can be assigned only to active episodes of its own series.');
                }
            }
            $before = $selected->getEpisodeIds();
            if ($extra === null) {
                $selected->assignEpisodes($episodeIds, assigned: true);
            } else {
                $selected->assignExtra($extra, assigned: true);
            }
            $selected->review(null);
            $this->catalog->savePlacement($selected);
            $this->catalog->flush();
            $after = $selected->getEpisodeIds();
            $retained = self::retainedEpisodeIds($placements, [$placementId->toString() => $after], [$placementId->toString() => $before]);
            $moves = self::sameEpisodeSet($before, $after) ? [] : [new WatchedMarkVideoMove($selected->getVideoId(), $before, $after)];
            $this->marks->move($operationId, $moves, $retained);
            $active = self::episodeSet(array_map(static fn (VideoPlacement $placement): array => $placement->getEpisodeIds(), $placements));
            foreach ($before as $removed) {
                $key = $removed->toString();
                if (!isset($active[$key]) && $episodes[$key]->getProviderId() === null) {
                    $episodes[$key]->retire();
                    $this->catalog->saveEpisode($episodes[$key]);
                }
            }

            return true;
        });
    }

    /** @return array<int, TvSeason> */
    private function seasonsByNumber(Uuid $seriesId): array
    {
        $seasons = [];
        foreach ($this->catalog->seasonsBySeries($seriesId) as $season) {
            $seasons[$season->getSeasonNumber()] = $season;
        }

        return $seasons;
    }

    /** @return array<string, TvEpisode> */
    private function episodesById(Uuid $seriesId): array
    {
        $episodes = [];
        foreach ($this->catalog->episodesBySeries($seriesId) as $episode) {
            $episodes[$episode->getId()->toString()] = $episode;
        }

        return $episodes;
    }

    /**
     * @param array<int, TvSeason> $seasons
     * @param array<string, TvEpisode> $episodes
     * @return array{array<string, string>, array<string, string>}
     */
    private static function existingIdentities(array $seasons, array $episodes, bool $sameEntry): array
    {
        $seasonNumbers = [];
        foreach ($seasons as $number => $season) {
            $seasonNumbers[$season->getId()->toString()] = $number;
        }
        $coordinates = [];
        $native = [];
        foreach ($episodes as $id => $episode) {
            $number = $episode->getEpisodeNumber();
            $season = $episode->getSeasonId();
            if ($number !== null && $season !== null) {
                $coordinates[$id] = self::coordinate($seasonNumbers[$season->toString()], $number);
            }
            if ($sameEntry && $episode->getProviderId() !== null) {
                $native[$id] = $episode->getProviderId();
            }
        }

        return [$coordinates, $native];
    }

    /**
     * @param array<string, PlacementDecision> $decisions
     * @return array{array<string, EpisodeCoordinate>, array<string, string>}
     */
    private static function desiredStructure(VideoTitle $metadata, array $decisions): array
    {
        $coordinates = [];
        $providerCoordinates = [];
        foreach ($metadata->seasons as $season) {
            foreach ($season->episodes as $episode) {
                $coordinate = self::coordinate($season->number, $episode->number);
                if ($season->number < 0 || $episode->number < 1 || trim($episode->id) === ''
                    || isset($coordinates[$coordinate]) || isset($providerCoordinates[$episode->id])
                ) {
                    throw new UnexpectedValueException('The provider supplied conflicting episode identities or numbers.');
                }
                $coordinates[$coordinate] = [
                    'seasonNumber' => $season->number,
                    'episodeNumber' => $episode->number,
                    'provider' => $episode,
                ];
                $providerCoordinates[$episode->id] = $coordinate;
            }
        }
        foreach ($decisions as $decision) {
            foreach ($decision['targets'] as $target) {
                $coordinate = self::coordinate($target['seasonNumber'], $target['episodeNumber']);
                $coordinates[$coordinate] ??= [
                    'seasonNumber' => $target['seasonNumber'],
                    'episodeNumber' => $target['episodeNumber'],
                    'provider' => null,
                ];
            }
        }

        return [$coordinates, $providerCoordinates];
    }

    /**
     * @param array<string, EpisodeCoordinate> $coordinates
     * @param array<string, string> $providerCoordinates
     * @param array<string, string> $existingCoordinates
     * @param array<string, string> $nativeIds
     * @param list<VideoPlacement> $placements
     * @param array<string, PlacementDecision> $decisions
     * @return array{array<string, list<string>>, array<string, true>}
     */
    private static function episodeCandidates(
        array $coordinates,
        array $providerCoordinates,
        array $existingCoordinates,
        array $nativeIds,
        array $placements,
        array $decisions,
    ): array {
        $candidates = [];
        $absorbable = [];
        foreach ($nativeIds as $id => $nativeId) {
            if (isset($providerCoordinates[$nativeId])) {
                $candidates[$providerCoordinates[$nativeId]][] = $id;
                $absorbable[$id] = true;
            }
        }
        $claims = [];
        $handAssigned = [];
        foreach ($placements as $placement) {
            $from = $placement->getEpisodeIds();
            if ($placement->isAssigned()) {
                foreach ($from as $id) {
                    $handAssigned[$id->toString()] = true;
                }
            }
            $targets = $decisions[$placement->getId()->toString()]['targets'] ?? [];
            if (count($from) === 1 && count($targets) === 1 && $targets[0]['providerId'] !== null) {
                $target = $targets[0];
                $claims[$from[0]->toString()][self::coordinate($target['seasonNumber'], $target['episodeNumber'])] = true;
            }
        }
        foreach ($existingCoordinates as $id => $coordinate) {
            if (!isset($nativeIds[$id]) && isset($coordinates[$coordinate])
                && (!isset($claims[$id]) || isset($claims[$id][$coordinate]))
            ) {
                $candidates[$coordinate][] = $id;
            }
        }
        foreach ($claims as $id => $destinations) {
            if (!isset($nativeIds[$id]) && !isset($handAssigned[$id]) && count($destinations) === 1) {
                $coordinate = array_key_first($destinations);
                if (!in_array($id, $candidates[$coordinate] ?? [], true)) {
                    $candidates[$coordinate][] = $id;
                }
                $absorbable[$id] = true;
            }
        }

        return [$candidates, $absorbable];
    }

    /**
     * @param array<int, TvSeason> $seasons
     * @param array<string, EpisodeCoordinate> $coordinates
     * @return array<int, TvSeason>
     */
    private function addSeasons(Uuid $seriesId, array $seasons, VideoTitle $metadata, array $coordinates): array
    {
        $numbers = array_unique([
            ...array_map(static fn (VideoSeason $season): int => $season->number, $metadata->seasons),
            ...array_column($coordinates, 'seasonNumber'),
        ]);
        foreach ($numbers as $number) {
            if (!isset($seasons[$number])) {
                $seasons[$number] = TvSeason::create($seriesId, $number);
                $this->catalog->saveSeason($seasons[$number]);
            }
        }
        $this->catalog->flush();

        return $seasons;
    }

    /**
     * @param array<int, TvSeason> $seasons
     * @param array<string, EpisodeCoordinate> $coordinates
     * @param array<string, TvEpisode> $episodes
     * @param array<string, list<string>> $candidates
     * @param array<string, true> $absorbable
     * @return array{array<string, TvEpisode>, array<string, Uuid>}
     */
    private static function structureEpisodes(
        Uuid $seriesId,
        array $seasons,
        array $coordinates,
        array $episodes,
        array $candidates,
        array $absorbable,
    ): array {
        $targets = [];
        $merges = [];
        foreach ($coordinates as $key => $coordinate) {
            $ids = $candidates[$key] ?? [];
            $target = $ids === [] ? null : $episodes[$ids[0]];
            $target ??= TvEpisode::create(
                $seriesId,
                $seasons[$coordinate['seasonNumber']]->getId(),
                $coordinate['episodeNumber'],
                $coordinate['provider']->title ?? 'Episode ' . $coordinate['episodeNumber'],
            );
            foreach (array_slice($ids, 1) as $source) {
                if (isset($absorbable[$source])) {
                    $merges[$source] = $target->getId();
                }
            }
            $targets[$key] = $target;
        }

        return [$targets, $merges];
    }

    /**
     * @param list<VideoPlacement> $placements
     * @param array<string, PlacementDecision> $decisions
     * @param array<string, TvEpisode> $targets
     * @param array<string, string> $nativeIds
     * @param array<string, string> $providerCoordinates
     * @param array<string, Uuid> $merges
     * @param array<string, ExtraKind|null> $resets
     * @return array<string, list<Uuid>>
     */
    private static function placementAssignments(
        array $placements,
        array $decisions,
        array $targets,
        array $nativeIds,
        array $providerCoordinates,
        array $merges,
        array $resets,
    ): array {
        $assignments = [];
        foreach ($placements as $placement) {
            $id = $placement->getId()->toString();
            if (isset($resets[$id])) {
                $assignments[$id] = [];
                continue;
            }
            if (!isset($decisions[$id])) {
                $assignments[$id] = self::remap($placement->getEpisodeIds(), $merges);
                continue;
            }
            $next = [];
            foreach ($decisions[$id]['targets'] as $target) {
                $next[] = $targets[self::coordinate($target['seasonNumber'], $target['episodeNumber'])]->getId();
            }
            if (!array_key_exists($id, $resets)) {
                foreach ($placement->getEpisodeIds() as $previous) {
                    $nativeId = $nativeIds[$previous->toString()] ?? null;
                    if ($nativeId !== null && !isset($providerCoordinates[$nativeId])) {
                        $next[] = $previous;
                    }
                }
            }
            $assignments[$id] = self::remap($next, $merges);
        }

        return $assignments;
    }

    /**
     * @param array<int, TvSeason> $seasons
     * @param array<string, TvEpisode> $episodes
     * @param array<string, TvEpisode> $targets
     * @param array<string, EpisodeCoordinate> $coordinates
     * @param array<string, string> $existingCoordinates
     * @param array<string, list<Uuid>> $assignments
     * @param array<string, Uuid> $merges
     * @param array<string, int> $absoluteNumbers
     */
    private function saveStructure(
        array $seasons,
        array $episodes,
        array $targets,
        array $coordinates,
        array $existingCoordinates,
        array $assignments,
        array $merges,
        array $absoluteNumbers,
        bool $sameEntry,
    ): void {
        $linked = self::episodeSet($assignments);
        $absoluteCounts = array_count_values($absoluteNumbers);
        $targetCoordinates = [];
        foreach ($targets as $coordinate => $episode) {
            $targetCoordinates[$episode->getId()->toString()] = $coordinate;
        }
        foreach ($episodes as $id => $episode) {
            $old = $existingCoordinates[$id] ?? null;
            $new = $targetCoordinates[$id] ?? null;
            if (isset($merges[$id]) || ($new !== null && $new !== $old) || ($new === null && !isset($linked[$id]))) {
                $episode->retire();
            } elseif ($new === null && $old !== null && isset($targets[$old])) {
                $episode->place(null, null);
            } elseif ($sameEntry || $new !== null || $episode->getProviderId() === null) {
                continue;
            }
            if (!$sameEntry && $new === null) {
                $episode->clearMatch();
            }
            $this->catalog->saveEpisode($episode);
        }
        // Flush released numbers before installing replacements against the immediate unique index.
        $this->catalog->flush();
        foreach ($targets as $key => $episode) {
            $coordinate = $coordinates[$key];
            $record = $coordinate['provider'];
            $episode->place($seasons[$coordinate['seasonNumber']]->getId(), $coordinate['episodeNumber']);
            if ($record !== null) {
                $absolute = $absoluteNumbers[$record->id] ?? null;
                $episode->match($record->id, $record->externalIds);
                $episode->applyStructure(
                    $record->airDate === null ? null : new DateTimeImmutable($record->airDate),
                    $absolute !== null && $absoluteCounts[$absolute] === 1 ? $absolute : null,
                );
            } elseif (!$sameEntry) {
                $episode->clearMatch();
            }
            $this->catalog->saveEpisode($episode);
        }
        $this->catalog->flush();
    }

    /**
     * @param list<VideoPlacement> $placements
     * @param array<string, list<Uuid>> $assignments
     * @param array<string, PlacementDecision> $decisions
     * @param array<string, Uuid> $merges
     * @param array<string, ExtraKind|null> $resets
     */
    private function writePlacementChanges(
        Uuid $operationId,
        array $placements,
        array $assignments,
        array $decisions,
        array $merges,
        array $resets,
    ): void {
        $retained = self::retainedEpisodeIds($placements, $assignments);
        $moves = [];
        foreach ($placements as $placement) {
            $id = $placement->getId()->toString();
            $before = $placement->getEpisodeIds();
            $after = $assignments[$id];
            $reason = $placement->getReviewReason();
            $placementRechecked = isset($decisions[$id]) && !in_array($reason, ['duplicate_file', 'assignment_reset'], true);
            $reset = array_key_exists($id, $resets);
            if ($reset) {
                $reason = 'assignment_reset';
                if ($resets[$id] === null) {
                    $placement->assignEpisodes($after);
                } else {
                    $placement->assignExtra($resets[$id]);
                }
            } elseif ($placementRechecked) {
                $reason = $decisions[$id]['reviewReason'];
            }
            $changed = !self::sameEpisodeIds($before, $after);
            if ($changed && !$reset) {
                $placement->assignEpisodes($after, $placement->isAssigned());
            }
            if (!self::sameEpisodeSet($before, $after)) {
                $moves[] = new WatchedMarkVideoMove($placement->getVideoId(), $before, $after);
            }
            if ($reset || $changed || $reason !== $placement->getReviewReason() || ($reason !== null && $placementRechecked)) {
                $placement->review($reason);
                $this->catalog->savePlacement($placement);
            }
        }
        $this->catalog->flush();
        $merged = [];
        foreach ($merges as $source => $target) {
            $merged[] = new WatchedMarkEpisodeMerge(Uuid::fromString($source), $target);
        }
        $this->marks->move($operationId, $moves, $retained, $merged);
    }

    /**
     * @param list<VideoPlacement> $placements
     * @param array<string, list<Uuid>> $assignments
     * @param array<string, list<Uuid>> $beforeOverrides
     * @return list<Uuid>
     */
    private static function retainedEpisodeIds(array $placements, array $assignments, array $beforeOverrides = []): array
    {
        $retained = [];
        foreach ($placements as $placement) {
            $id = $placement->getId()->toString();
            $before = $beforeOverrides[$id] ?? $placement->getEpisodeIds();
            $after = self::episodeSet([$assignments[$id] ?? $before]);
            foreach ($before as $episode) {
                $key = $episode->toString();
                if (isset($after[$key])) {
                    $retained[$key] = $episode;
                }
            }
        }

        return array_values($retained);
    }

    /** @param iterable<list<Uuid>> $lists
     * @return array<string, Uuid>
     */
    private static function episodeSet(iterable $lists): array
    {
        $result = [];
        foreach ($lists as $list) {
            foreach ($list as $episode) {
                $result[$episode->toString()] = $episode;
            }
        }

        return $result;
    }

    /** @param list<Uuid> $ids
     * @param array<string, Uuid> $merges
     * @return list<Uuid>
     */
    private static function remap(array $ids, array $merges): array
    {
        $result = [];
        foreach ($ids as $id) {
            $next = $merges[$id->toString()] ?? $id;
            $result[$next->toString()] = $next;
        }

        return array_values($result);
    }

    /** @param list<Uuid> $before
     * @param list<Uuid> $after
     */
    private static function sameEpisodeIds(array $before, array $after): bool
    {
        return array_map(static fn (Uuid $id): string => $id->toString(), $before)
            === array_map(static fn (Uuid $id): string => $id->toString(), $after);
    }

    /** @param list<Uuid> $before
     * @param list<Uuid> $after
     */
    private static function sameEpisodeSet(array $before, array $after): bool
    {
        return count($before) === count($after)
            && array_diff_key(self::episodeSet([$before]), self::episodeSet([$after])) === [];
    }

    private static function coordinate(int $seasonNumber, int $episodeNumber): string
    {
        return $seasonNumber . ':' . $episodeNumber;
    }
}
