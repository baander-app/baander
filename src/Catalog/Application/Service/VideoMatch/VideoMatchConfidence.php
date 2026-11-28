<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service\VideoMatch;

use App\Metadata\Application\DTO\Video\VideoKind;
use App\Metadata\Application\DTO\Video\VideoOrdering;
use App\Metadata\Application\DTO\Video\VideoRequest;
use App\Metadata\Application\DTO\Video\VideoTitle;

final readonly class VideoMatchConfidence
{
    public const int MINIMUM_SCORE = 90;
    public const int MINIMUM_LEAD = 10;

    /**
     * @param list<array<string, mixed>> $parses
     * @return int<0, 100>
     */
    public function score(VideoRequest $local, VideoTitle $candidate, array $parses = []): int
    {
        $titleFit = self::titleFit($local->title, $candidate->entry->title);
        $yearFit = match (true) {
            $local->year === null || $candidate->entry->year === null => 0.5,
            $local->year === $candidate->entry->year => 1.0,
            default => 0.0,
        };
        $score = $local->kind === VideoKind::Movie
            ? 85 * $titleFit + 15 * $yearFit
            : 70 * $titleFit + 15 * $yearFit + 15 * self::numberFit($candidate, $parses);

        return max(0, min(100, (int) round($score)));
    }

    /**
     * @param list<array{title: VideoTitle, score: int<0, 100>}> $scored
     * @return array{match: VideoTitle|null, reason: string|null, score: int<0, 100>|null}
     */
    public function decide(array $scored): array
    {
        if ($scored === []) {
            return ['match' => null, 'reason' => 'no_candidates', 'score' => null];
        }
        usort($scored, static fn (array $one, array $other): int => $other['score'] <=> $one['score']
            ?: strcmp($one['title']->entry->id, $other['title']->entry->id));
        $best = $scored[0];
        $runner = $scored[1] ?? null;
        $reason = match (true) {
            $best['score'] < self::MINIMUM_SCORE => 'low_score',
            $runner !== null && $best['score'] - $runner['score'] < self::MINIMUM_LEAD => 'close_runner_up',
            default => null,
        };

        return ['match' => $reason === null ? $best['title'] : null, 'reason' => $reason, 'score' => $best['score']];
    }

    private static function titleFit(string $local, string $candidate): float
    {
        $one = self::foldTitle($local);
        $other = self::foldTitle($candidate);
        if ($one === '' || $other === '') {
            return 0.0;
        }
        if ($one === $other) {
            return 1.0;
        }

        return 0.9 * (1 - levenshtein($one, $other) / max(strlen($one), strlen($other)));
    }

    private static function foldTitle(string $title): string
    {
        $lower = mb_strtolower($title);
        $title = str_replace(["'", '’', '‘'], '', $lower);

        return (preg_replace('/[^\p{L}\p{N}]+/u', ' ', $title) ?? '') |> trim(...);
    }

    /** @param list<array<string, mixed>> $parses */
    private static function numberFit(VideoTitle $candidate, array $parses): float
    {
        $positions = [];
        $absolute = [];
        $dates = [];
        foreach ($candidate->seasons as $season) {
            foreach ($season->episodes as $episode) {
                $positions[$season->number][$episode->number] = true;
                if ($season->number > 0 && $episode->absoluteNumber !== null) {
                    $absolute[$episode->absoluteNumber] = ($absolute[$episode->absoluteNumber] ?? 0) + 1;
                }
                if ($episode->airDate !== null) {
                    $dates[$episode->airDate] = ($dates[$episode->airDate] ?? 0) + 1;
                }
            }
        }
        $hasAbsolute = array_any($candidate->orderings, static fn (VideoOrdering $ordering): bool => $ordering->kind === 'absolute');
        if ($absolute === [] && !$hasAbsolute) {
            $number = 0;
            foreach ($positions as $season => $episodes) {
                if ($season !== 0) {
                    $number += count($episodes);
                }
            }
            $absolute = $number === 0 ? [] : array_fill(1, $number, 1);
        }
        $checked = 0;
        $matched = 0;
        foreach ($parses as $parse) {
            if (($parse['extraKind'] ?? null) !== null || ($parse['sample'] ?? false) === true) {
                continue;
            }
            $season = $parse['seasonNumber'] ?? null;
            $episodes = $parse['episodeNumbers'] ?? [];
            $number = $parse['absoluteNumber'] ?? null;
            $date = $parse['airDate'] ?? null;
            if (is_int($season) && is_array($episodes) && $episodes !== []) {
                ++$checked;
                $matched += (int) array_all($episodes, static fn (mixed $episode): bool => is_int($episode) && isset($positions[$season][$episode]));
            } elseif (is_int($number)) {
                ++$checked;
                $matched += (int) (($absolute[$number] ?? 0) === 1);
            } elseif (is_string($date) && $date !== '') {
                ++$checked;
                $matched += (int) (($dates[$date] ?? 0) === 1);
            }
        }

        return $checked === 0 ? 0.5 : $matched / $checked;
    }
}
