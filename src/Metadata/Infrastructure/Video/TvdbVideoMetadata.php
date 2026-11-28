<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Video;

use App\Metadata\Application\DTO\Video\VideoArtwork;
use App\Metadata\Application\DTO\Video\VideoCandidate;
use App\Metadata\Application\DTO\Video\VideoCredit;
use App\Metadata\Application\DTO\Video\VideoEpisode;
use App\Metadata\Application\DTO\Video\VideoKind;
use App\Metadata\Application\DTO\Video\VideoOrdering;
use App\Metadata\Application\DTO\Video\VideoProviderId;
use App\Metadata\Application\DTO\Video\VideoProviderProblem;
use App\Metadata\Application\DTO\Video\VideoRequest;
use App\Metadata\Application\DTO\Video\VideoSeason;
use App\Metadata\Application\DTO\Video\VideoTitle;
use App\Metadata\Application\Exception\MetadataProviderDisabled;
use App\Metadata\Infrastructure\Api\Tvdb\TvdbAdapter;
use App\Metadata\Infrastructure\Api\Tvdb\TvdbHttpException;
use App\Metadata\Infrastructure\Api\Tvdb\TvdbNotConfiguredException;
use Throwable;
use UnexpectedValueException;

final readonly class TvdbVideoMetadata implements VideoProviderAdapter
{
    private const array ORDERING_TYPES = [
        'official', 'dvd', 'absolute', 'alternate', 'regional', 'altdvd', 'alttwo',
    ];

    public function __construct(private TvdbAdapter $tvdb) {}

    public function provider(): VideoProviderId
    {
        return VideoProviderId::Tvdb;
    }

    public function isConfigured(): bool
    {
        return $this->tvdb->isConfigured();
    }

    public function search(VideoRequest $request): array|VideoProviderProblem
    {
        if ($request->kind !== VideoKind::Series) {
            return [];
        }
        try {
            $problem = $this->availability();
            if ($problem !== null) {
                return $problem;
            }

            return array_map(self::searchCandidate(...), $this->tvdb->searchSeries($request->title, $request->year));
        } catch (Throwable $failure) {
            return self::problem($failure);
        }
    }

    public function identify(VideoRequest $request): VideoTitle|VideoProviderProblem|null
    {
        if ($request->kind !== VideoKind::Series) {
            return null;
        }
        try {
            $problem = $this->availability();
            if ($problem !== null) {
                return $problem;
            }
            $id = $request->externalIds['tvdb'] ?? null;
            if ($id !== null && (!ctype_digit($id) || (int) $id < 1)) {
                return VideoProviderProblem::Refused;
            }
            $record = $id === null ? $this->remoteRecord($request) : $this->tvdb->seriesExtended((int) $id);
            if ($record === null) {
                return null;
            }
        } catch (TvdbHttpException $failure) {
            return $failure->status === 404 ? null : self::problem($failure);
        } catch (Throwable $failure) {
            return self::problem($failure);
        }

        try {
            return $this->series($record, $request->ordering);
        } catch (Throwable $failure) {
            return $failure instanceof TvdbHttpException && $failure->status === 404
                ? VideoProviderProblem::Unavailable
                : self::problem($failure);
        }
    }

    /** @return array<string, mixed>|null */
    private function remoteRecord(VideoRequest $request): ?array
    {
        foreach (['imdb', 'tmdb'] as $source) {
            $remoteId = $request->externalIds[$source] ?? null;
            if ($remoteId === null) {
                continue;
            }
            $matches = $this->remoteMatches($source, $remoteId);
            if ($matches === []) {
                continue;
            }
            if (count($matches) !== 1) {
                return null;
            }
            $record = reset($matches);
            $ids = self::identifiers($record);
            foreach (['imdb', 'tmdb'] as $namespace) {
                $requested = $request->externalIds[$namespace] ?? null;
                if ($requested !== null && isset($ids[$namespace]) && $ids[$namespace] !== $requested) {
                    return null;
                }
            }

            return $record;
        }

        return null;
    }

    /** @return array<int, array<string, mixed>> */
    private function remoteMatches(string $source, string $remoteId): array
    {
        $matches = [];
        $checked = [];
        foreach ($this->tvdb->searchRemoteId($remoteId) as $result) {
            $series = $result['series'] ?? null;
            if (!is_array($series)) {
                continue;
            }
            $id = self::positiveId($series['id'] ?? null);
            if (isset($checked[$id])) {
                continue;
            }
            $checked[$id] = true;
            $record = $this->tvdb->seriesExtended($id);
            if ((self::identifiers($record)[$source] ?? null) === $remoteId) {
                $matches[$id] = $record;
            }
        }

        return $matches;
    }

    /** @param array<string, mixed> $record
     * @return array<string, string>
     */
    private static function identifiers(array $record, bool $person = false): array
    {
        $ids = [$person ? 'tvdb_person' : 'tvdb' => (string) self::positiveId($record['id'] ?? null)];
        foreach (self::rows($record['remoteIds'] ?? []) as $remote) {
            $id = $remote['id'] ?? null;
            $source = $remote['sourceName'] ?? null;
            if ($source === 'IMDB' && is_string($id) && preg_match($person ? '/^nm[0-9]+$/D' : '/^tt[0-9]+$/D', $id) === 1) {
                $namespace = $person ? 'imdb_name' : 'imdb';
            } elseif ($source === 'TheMovieDB.com' && (is_string($id) || is_int($id)) && ctype_digit((string) $id) && (int) $id > 0) {
                $id = (string) $id;
                $namespace = $person ? 'tmdb_person' : 'tmdb';
            } else {
                continue;
            }
            if (isset($ids[$namespace]) && $ids[$namespace] !== $id) {
                throw new UnexpectedValueException('TheTVDB record has conflicting remote identities.');
            }
            $ids[$namespace] = $id;
        }

        return $ids;
    }

    private function availability(): ?VideoProviderProblem
    {
        return match (true) {
            !$this->tvdb->isEnabled() => VideoProviderProblem::Disabled,
            !$this->tvdb->isConfigured() => VideoProviderProblem::NotConfigured,
            default => null,
        };
    }

    private static function problem(Throwable $failure): VideoProviderProblem
    {
        if ($failure instanceof MetadataProviderDisabled) {
            return VideoProviderProblem::Disabled;
        }
        if ($failure instanceof TvdbNotConfiguredException) {
            return VideoProviderProblem::NotConfigured;
        }
        if ($failure instanceof TvdbHttpException && $failure->status >= 400 && $failure->status < 500
            && !in_array($failure->status, [401, 403, 408, 429], true)
        ) {
            return VideoProviderProblem::Refused;
        }

        return VideoProviderProblem::Unavailable;
    }

    /** @param array<string, mixed> $row */
    private static function searchCandidate(array $row): VideoCandidate
    {
        $id = $row['tvdb_id'] ?? null;
        if (!is_string($id) || !ctype_digit($id) || (int) $id < 1) {
            throw new UnexpectedValueException('TheTVDB search result has no series ID.');
        }
        $name = self::text($row['name'] ?? null) ?? throw new UnexpectedValueException('TheTVDB search result has no name.');

        return new VideoCandidate($id, $name, self::year($row['year'] ?? $row['first_air_time'] ?? null), self::identifiers(['id' => (int) $id, 'remoteIds' => $row['remote_ids'] ?? []]));
    }

    /** @param array<string, mixed> $record */
    private function series(array $record, string $selected): VideoTitle
    {
        $id = self::positiveId($record['id'] ?? null);
        $name = self::text($record['name'] ?? null) ?? throw new UnexpectedValueException('TheTVDB series has no name.');
        $entry = new VideoCandidate((string) $id, $name, self::year($record['firstAired'] ?? null), self::identifiers($record));
        $orderings = ['aired' => new VideoOrdering('aired', 'Aired', 'aired')];
        foreach (self::rows($record['seasons'] ?? []) as $season) {
            $type = is_array($season['type'] ?? null) ? $season['type'] : [];
            $slug = $type['type'] ?? null;
            if (!is_string($slug) || !in_array($slug, self::ORDERING_TYPES, true)) {
                continue;
            }
            $ordering = $slug === 'official' ? 'aired' : $slug;
            $label = self::text($type['name'] ?? null) ?? self::text($type['alternateName'] ?? null)
                ?? ($ordering === 'dvd' ? 'DVD' : ucfirst($ordering));
            $orderings[$ordering] = new VideoOrdering($ordering, $label, $ordering);
        }
        $seasons = isset($orderings[$selected]) ? $this->seasons($record, $id, $orderings, $selected) : [];
        [$cast, $crew] = $this->credits($record['characters'] ?? null);

        return new VideoTitle(
            entry: $entry,
            overview: self::text($record['overview'] ?? null),
            poster: self::artwork($record['image'] ?? null),
            orderings: array_values($orderings),
            seasons: $seasons,
            cast: $cast,
            crew: $crew,
            runtime: is_int($record['averageRuntime'] ?? null) ? $record['averageRuntime'] : null,
            originalLanguage: self::text($record['originalLanguage'] ?? null),
            genres: array_map(static fn (array $genre): string => self::text($genre['name'] ?? null) ?? throw new UnexpectedValueException('TheTVDB genre has no name.'), self::rows($record['genres'] ?? [])),
        );
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, VideoOrdering> $orderings
     * @return list<VideoSeason>
     */
    private function seasons(array $record, int $seriesId, array $orderings, string $selected): array
    {
        $aired = $this->tvdb->seriesEpisodes($seriesId, 'official');
        $canonical = [];
        foreach ($aired as $episode) {
            $canonical[self::positiveId($episode['id'] ?? null)] = $episode;
        }
        $absolute = [];
        $absoluteNumbers = [];
        $absoluteEpisodes = [];
        if (isset($orderings['absolute'])) {
            $absoluteEpisodes = $this->tvdb->seriesEpisodes($seriesId, 'absolute');
            foreach ($absoluteEpisodes as $episode) {
                $id = self::positiveId($episode['id'] ?? null);
                if (isset($canonical[$id]) && self::number($canonical[$id]['seasonNumber'] ?? null) !== 0) {
                    $number = self::number($episode['number'] ?? null);
                    if (isset($absolute[$id]) || isset($absoluteNumbers[$number])) {
                        throw new UnexpectedValueException('TheTVDB absolute ordering contains conflicting episodes.');
                    }
                    $absolute[$id] = $number;
                    $absoluteNumbers[$number] = true;
                }
            }
        }
        $selectedEpisodes = match ($selected) {
            'aired' => $aired,
            'absolute' => $absoluteEpisodes,
            default => $this->tvdb->seriesEpisodes($seriesId, $selected),
        };
        $grouped = [];
        $seen = [];
        foreach ($selectedEpisodes as $episode) {
            $id = self::positiveId($episode['id'] ?? null);
            $seasonNumber = isset($canonical[$id]) && self::number($canonical[$id]['seasonNumber'] ?? null) === 0
                ? 0 : self::number($episode['seasonNumber'] ?? null);
            $number = $seasonNumber === 0 && isset($canonical[$id])
                ? self::number($canonical[$id]['number'] ?? null) : self::number($episode['number'] ?? null);
            $details = $this->tvdb->episodeExtended($id, $episode['lastUpdated'] ?? null);
            if (isset($seen[$id]) || isset($grouped[$seasonNumber][$number])) {
                throw new UnexpectedValueException('TheTVDB ordering contains conflicting episodes.');
            }
            $grouped[$seasonNumber][$number] = $this->episode(array_replace($canonical[$id] ?? [], $episode, $details), $number, $absolute[$id] ?? null);
            $seen[$id] = true;
        }
        foreach ($canonical as $id => $episode) {
            if (self::number($episode['seasonNumber'] ?? null) !== 0 || isset($seen[$id])) {
                continue;
            }
            $number = self::number($episode['number'] ?? null);
            if (isset($grouped[0][$number])) {
                throw new UnexpectedValueException('TheTVDB specials contain conflicting episode numbers.');
            }
            $details = $this->tvdb->episodeExtended($id, $episode['lastUpdated'] ?? null);
            $grouped[0][$number] = $this->episode(array_replace($episode, $details), $number, null);
        }
        ksort($grouped, SORT_NUMERIC);
        $seasons = [];
        foreach ($grouped as $number => $episodes) {
            ksort($episodes, SORT_NUMERIC);
            $season = self::seasonRecord($record, $selected === 'aired' ? 'official' : $selected, $number);
            $seasons[] = new VideoSeason($number, self::text($season['name'] ?? null), self::text($season['overview'] ?? null), self::artwork($season['image'] ?? null), array_values($episodes));
        }

        return $seasons;
    }

    /** @param array<string, mixed> $record */
    private function episode(array $record, int $number, ?int $absolute): VideoEpisode
    {
        $id = self::positiveId($record['id'] ?? null);
        $name = self::text($record['name'] ?? null) ?? throw new UnexpectedValueException('TheTVDB episode has no name.');
        [$cast, $crew] = $this->credits($record['characters'] ?? null);

        return new VideoEpisode(
            id: (string) $id,
            number: $number,
            title: $name,
            absoluteNumber: $absolute,
            airDate: self::text($record['aired'] ?? null),
            externalIds: self::identifiers($record),
            overview: self::text($record['overview'] ?? null),
            still: self::artwork($record['image'] ?? null),
            cast: $cast,
            crew: $crew,
        );
    }

    /** @return array{list<VideoCredit>|null, list<VideoCredit>|null} */
    private function credits(mixed $characters): array
    {
        if ($characters === null) {
            return [null, null];
        }
        $cast = [];
        $crew = [];
        foreach (self::rows($characters) as $character) {
            $personId = self::positiveId($character['peopleId'] ?? null);
            $person = $this->tvdb->personExtended($personId);
            if (self::positiveId($person['id'] ?? null) !== $personId) {
                throw new UnexpectedValueException('TheTVDB person response names another person.');
            }
            $name = self::text($person['name'] ?? $character['personName'] ?? null)
                ?? throw new UnexpectedValueException('TheTVDB credit has no person name.');
            $job = self::text($character['peopleType'] ?? null)
                ?? throw new UnexpectedValueException('TheTVDB credit has no role.');
            $isCast = in_array(strtolower($job), ['actor', 'actress', 'guest star', 'self', 'host'], true);
            $credit = new VideoCredit(
                identifiers: self::identifiers($person, person: true),
                name: $name,
                category: $isCast ? 'cast' : 'crew',
                role: $isCast ? 'actor' : self::role($job),
                job: $isCast ? 'Actor' : $job,
                characterName: $isCast ? self::text($character['name'] ?? null) : null,
                billingOrder: is_int($character['sort'] ?? null) ? $character['sort'] : null,
                providerCreditId: isset($character['id']) ? (string) self::positiveId($character['id']) : null,
                profileUrl: self::artwork($person['image'] ?? $character['personImgURL'] ?? null)?->path,
            );
            if ($isCast) {
                $cast[] = $credit;
            } else {
                $crew[] = $credit;
            }
        }

        return [$cast, $crew];
    }

    /** @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private static function seasonRecord(array $record, string $type, int $number): array
    {
        foreach (self::rows($record['seasons'] ?? []) as $season) {
            $seasonType = is_array($season['type'] ?? null) ? $season['type'] : [];
            if (($seasonType['type'] ?? null) === ($number === 0 ? 'official' : $type) && ($season['number'] ?? null) === $number) {
                return $season;
            }
        }

        return [];
    }

    private static function role(string $job): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($job)) ?? '', '_') ?: 'crew';
    }

    private static function artwork(mixed $path): ?VideoArtwork
    {
        $path = self::text($path);

        if ($path === null || str_starts_with($path, '//')) {
            return null;
        }
        $url = parse_url($path);
        if ($url === false || isset($url['user']) || isset($url['pass'])) {
            return null;
        }
        if (!isset($url['scheme']) && !isset($url['host'])) {
            $path = 'https://artworks.thetvdb.com/' . ltrim($path, '/');
            $url = parse_url($path);
        }
        if (!is_array($url) || ($url['scheme'] ?? null) !== 'https'
            || strtolower($url['host'] ?? '') !== 'artworks.thetvdb.com'
            || (isset($url['port']) && $url['port'] !== 443)
        ) {
            return null;
        }

        return new VideoArtwork($path);
    }

    private static function year(mixed $date): ?int
    {
        return is_string($date) && preg_match('/^[0-9]{4}(?:-|$)/', $date) === 1 ? (int) substr($date, 0, 4) : null;
    }

    private static function positiveId(mixed $id): int
    {
        return is_int($id) && $id > 0 ? $id : throw new UnexpectedValueException('TheTVDB record has no positive ID.');
    }

    private static function number(mixed $value): int
    {
        return is_int($value) && $value >= 0 ? $value : throw new UnexpectedValueException('TheTVDB episode has no valid numbering.');
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new UnexpectedValueException('TheTVDB response has no record list.');
        }
        foreach ($value as $row) {
            if (!is_array($row)) {
                throw new UnexpectedValueException('TheTVDB response has a malformed record.');
            }
        }

        return $value;
    }
}
