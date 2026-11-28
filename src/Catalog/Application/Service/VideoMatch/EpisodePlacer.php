<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service\VideoMatch;

use App\Catalog\Domain\Model\VideoPlacement;
use App\Metadata\Application\DTO\Video\VideoSeason;
use App\Shared\Domain\Model\Uuid;

/**
 * @phpstan-type PlacementTarget array{providerId:?string,seasonNumber:int,episodeNumber:int}
 * @phpstan-type PlacementResult array{targets:list<PlacementTarget>,reviewReason:?string}
 * @phpstan-type EpisodeIndex array{ids:array<string,PlacementTarget>,numbers:array<int,array<int,PlacementTarget>>,absolute:array<int,list<PlacementTarget>>,dates:array<string,list<PlacementTarget>>}
 */
final readonly class EpisodePlacer
{
    /**
     * @param list<VideoSeason> $seasons
     * @param list<VideoPlacement> $placements
     * @param array<string, string> $providerIdsByEpisode
     * @param list<VideoSeason>|null $airedSeasons
     * @return array<string, PlacementResult>
     */
    public function place(array $seasons, array $placements, array $providerIdsByEpisode, ?array $airedSeasons = null): array
    {
        return $this->plan($seasons, $placements, $providerIdsByEpisode, $airedSeasons)['placements'];
    }

    /**
     * @param list<VideoSeason> $seasons
     * @param list<VideoPlacement> $placements
     * @param array<string, string> $providerIdsByEpisode
     * @param list<VideoSeason>|null $airedSeasons
     * @return array{placements: array<string, PlacementResult>, absoluteNumbers: array<string, positive-int>}
     */
    public function plan(array $seasons, array $placements, array $providerIdsByEpisode, ?array $airedSeasons = null): array
    {
        $index = $this->index($seasons);
        $absoluteNumbers = $this->absoluteNumbers($seasons, $airedSeasons);
        foreach ($absoluteNumbers as $id => $number) {
            $index['absolute'][$number][] = $index['ids'][$id];
        }
        $results = [];
        foreach ($placements as $placement) {
            $parsed = $this->parsedTargets($placement->getRawParse(), $index);
            $nativeIds = array_map(
                static fn (Uuid $id): ?string => $providerIdsByEpisode[$id->toString()] ?? null,
                $placement->getEpisodeIds(),
            );
            $hasNative = array_any($nativeIds, static fn (?string $id): bool => $id !== null);
            $targets = $parsed;
            $unresolved = false;
            if ($hasNative) {
                $targets = [];
                foreach ($nativeIds as $position => $id) {
                    $candidate = $id === null ? ($parsed[$position] ?? null) : ($index['ids'][$id] ?? null);
                    if ($candidate === null) {
                        $unresolved = true;
                        continue;
                    }
                    $coordinate = $candidate['seasonNumber'] . ':' . $candidate['episodeNumber'];
                    $targets[$coordinate] = $candidate;
                }
                $targets = array_values($targets);
            }
            $reason = match (true) {
                $unresolved, $targets === [], array_any($targets, static fn (array $target): bool => $target['providerId'] === null) => 'no_candidates',
                $hasNative && !$this->sameTargets($targets, $parsed) => 'name_disagreement',
                default => null,
            };
            $results[$placement->getId()->toString()] = ['targets' => $targets, 'reviewReason' => $reason];
        }

        return ['placements' => $results, 'absoluteNumbers' => $absoluteNumbers];
    }

    /**
     * @param list<VideoSeason> $seasons
     * @return EpisodeIndex
     */
    private function index(array $seasons): array
    {
        $index = ['ids' => [], 'numbers' => [], 'absolute' => [], 'dates' => []];
        foreach ($seasons as $season) {
            foreach ($season->episodes as $episode) {
                $target = ['providerId' => $episode->id, 'seasonNumber' => $season->number, 'episodeNumber' => $episode->number];
                $index['ids'][$episode->id] = $target;
                $index['numbers'][$season->number][$episode->number] = $target;
                if ($episode->airDate !== null) {
                    $index['dates'][$episode->airDate][] = $target;
                }
            }
        }

        return $index;
    }

    /**
     * @param list<VideoSeason> $seasons
     * @param list<VideoSeason>|null $airedSeasons
     * @return array<string, positive-int>
     */
    public function absoluteNumbers(array $seasons, ?array $airedSeasons = null): array
    {
        $explicit = [];
        $selected = [];
        foreach ($seasons as $season) {
            foreach ($season->episodes as $episode) {
                if ($season->number === 0) {
                    continue;
                }
                $selected[$episode->id] = true;
                if ($episode->absoluteNumber !== null && $episode->absoluteNumber > 0) {
                    $explicit[$episode->id] = $episode->absoluteNumber;
                }
            }
        }
        if ($explicit !== [] || $airedSeasons === null) {
            return $explicit;
        }
        $episodes = [];
        foreach ($airedSeasons as $season) {
            if ($season->number === 0) {
                continue;
            }
            foreach ($season->episodes as $episode) {
                $episodes[$season->number][$episode->number] = $episode->id;
            }
        }
        ksort($episodes, SORT_NUMERIC);
        $absolute = [];
        $number = 0;
        foreach ($episodes as $season) {
            ksort($season, SORT_NUMERIC);
            foreach ($season as $id) {
                ++$number;
                if (isset($selected[$id])) {
                    $absolute[$id] = $number;
                }
            }
        }

        return $absolute;
    }

    /**
     * @param array<string,mixed> $raw
     * @param EpisodeIndex $index
     * @return list<PlacementTarget>
     */
    private function parsedTargets(array $raw, array $index): array
    {
        $season = $raw['seasonNumber'] ?? null;
        $numbers = $raw['episodeNumbers'] ?? [];
        if (is_int($season) && $season >= 0 && is_array($numbers) && $numbers !== []) {
            if (array_any($numbers, static fn (mixed $number): bool => !is_int($number) || $number < 1)) {
                return [];
            }
            $targets = [];
            foreach (array_unique($numbers) as $number) {
                $targets[] = $index['numbers'][$season][$number]
                    ?? ['providerId' => null, 'seasonNumber' => $season, 'episodeNumber' => $number];
            }

            return $targets;
        }
        $absolute = $raw['absoluteNumber'] ?? null;
        if (is_int($absolute) && $absolute > 0) {
            $matches = $index['absolute'][$absolute] ?? [];

            return count($matches) === 1 ? $matches : [];
        }
        $date = $raw['airDate'] ?? null;
        $matches = is_string($date) ? ($index['dates'][$date] ?? []) : [];

        return count($matches) === 1 ? $matches : [];
    }

    /** @param list<PlacementTarget> $first
     * @param list<PlacementTarget> $second
     */
    private function sameTargets(array $first, array $second): bool
    {
        $firstIds = array_column($first, 'providerId');
        $secondIds = array_column($second, 'providerId');
        sort($firstIds);
        sort($secondIds);

        return $firstIds === $secondIds;
    }
}
