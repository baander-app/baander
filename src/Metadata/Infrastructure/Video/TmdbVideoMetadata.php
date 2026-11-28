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
use App\Metadata\Infrastructure\Api\Tmdb\TmdbAdapter;
use App\Metadata\Infrastructure\Api\Tmdb\TmdbHttpException;
use Throwable;
use UnexpectedValueException;

final readonly class TmdbVideoMetadata implements VideoProviderAdapter
{
    public function __construct(private TmdbAdapter $tmdb) {}

    public function provider(): VideoProviderId
    {
        return VideoProviderId::Tmdb;
    }

    public function isConfigured(): bool
    {
        return $this->tmdb->isConfigured();
    }

    public function search(VideoRequest $request): array|VideoProviderProblem
    {
        try {
            $problem = $this->availability();
            if ($problem !== null) {
                return $problem;
            }
            $response = $request->kind === VideoKind::Series
                ? $this->tmdb->searchTvSeries($request->title, $request->year)
                : $this->tmdb->searchMovieRecords($request->title, $request->year);
            $results = self::rows($response['results'] ?? null);

            return array_map(fn (array $row): VideoCandidate => $this->candidate($row, $request->kind), $results);
        } catch (Throwable $exception) {
            return self::problem($exception);
        }
    }

    public function identify(VideoRequest $request): VideoTitle|VideoProviderProblem|null
    {
        try {
            $problem = $this->availability();
            if ($problem !== null) {
                return $problem;
            }
            $id = $this->entryId($request);
            if ($id === null) {
                return null;
            }
            if ($id === false) {
                return VideoProviderProblem::Refused;
            }
            $record = $request->kind === VideoKind::Series
                ? $this->tmdb->lookupTvSeriesDetails($id)
                : $this->tmdb->lookupMovieDetails($id);
            $entry = $this->candidate($record, $request->kind);
            if ($entry->id !== (string) $id) {
                return VideoProviderProblem::Unavailable;
            }
            $suppliedIds = array_intersect_key($request->externalIds, ['tmdb' => true, 'tvdb' => true, 'imdb' => true]);
            if ($request->kind === VideoKind::Series && count($suppliedIds) > 1) {
                foreach ($suppliedIds as $source => $suppliedId) {
                    if (isset($entry->externalIds[$source]) && $entry->externalIds[$source] !== $suppliedId) {
                        return null;
                    }
                }
            }
        } catch (TmdbHttpException $exception) {
            return $exception->status === 404 ? null : self::problem($exception);
        } catch (Throwable $exception) {
            return self::problem($exception);
        }

        try {
            return $request->kind === VideoKind::Series
                ? $this->series($record, $entry, $request->ordering)
                : $this->movie($record, $entry);
        } catch (Throwable $exception) {
            return $exception instanceof TmdbHttpException && $exception->status === 404
                ? VideoProviderProblem::Unavailable
                : self::problem($exception);
        }
    }

    private function availability(): ?VideoProviderProblem
    {
        if (!$this->tmdb->isEnabled()) {
            return VideoProviderProblem::Disabled;
        }

        return $this->isConfigured() ? null : VideoProviderProblem::NotConfigured;
    }

    private static function problem(Throwable $exception): VideoProviderProblem
    {
        if ($exception instanceof MetadataProviderDisabled) {
            return VideoProviderProblem::Disabled;
        }
        if ($exception instanceof TmdbHttpException && $exception->status >= 400 && $exception->status < 500
            && !in_array($exception->status, [401, 403, 408, 429], true)
        ) {
            return VideoProviderProblem::Refused;
        }

        return VideoProviderProblem::Unavailable;
    }

    private function entryId(VideoRequest $request): int|false|null
    {
        $id = $request->externalIds['tmdb'] ?? null;
        if ($id !== null) {
            return ctype_digit($id) && (int) $id > 0 ? (int) $id : false;
        }
        $source = isset($request->externalIds['tvdb']) && $request->kind === VideoKind::Series ? 'tvdb' : 'imdb';
        $externalId = $request->externalIds[$source] ?? null;
        if ($externalId === null) {
            return null;
        }
        $response = $this->tmdb->findVideo($externalId, $source . '_id');
        $results = self::rows($response[$request->kind === VideoKind::Series ? 'tv_results' : 'movie_results'] ?? null);
        if ($results === [] && $source === 'tvdb' && isset($request->externalIds['imdb'])) {
            $response = $this->tmdb->findVideo($request->externalIds['imdb'], 'imdb_id');
            $results = self::rows($response['tv_results'] ?? null);
        }
        if (count($results) !== 1) {
            return null;
        }

        return self::positiveId($results[0]['id'] ?? null);
    }

    /** @param array<string, mixed> $row */
    private function candidate(array $row, VideoKind $kind): VideoCandidate
    {
        $id = self::positiveId($row['id'] ?? null);
        $title = self::text($row[$kind === VideoKind::Series ? 'name' : 'title'] ?? null)
            ?? throw new UnexpectedValueException('TMDB title has no name.');
        $date = self::text($row[$kind === VideoKind::Series ? 'first_air_date' : 'release_date'] ?? null);
        $ids = ['tmdb' => (string) $id];
        $external = is_array($row['external_ids'] ?? null) ? $row['external_ids'] : [];
        $imdb = self::text($external['imdb_id'] ?? $row['imdb_id'] ?? null);
        if ($imdb !== null) {
            $ids['imdb'] = $imdb;
        }
        $tvdb = $external['tvdb_id'] ?? null;
        if (is_int($tvdb) && $tvdb > 0) {
            $ids['tvdb'] = (string) $tvdb;
        }

        return new VideoCandidate((string) $id, $title, self::year($date), $ids);
    }

    /** @param array<string, mixed> $record */
    private function movie(array $record, VideoCandidate $entry): VideoTitle
    {
        $credits = is_array($record['credits'] ?? null) ? $record['credits'] : [];
        $collection = is_array($record['belongs_to_collection'] ?? null) ? $record['belongs_to_collection'] : [];

        return new VideoTitle(
            entry: $entry,
            overview: self::text($record['overview'] ?? null),
            poster: self::artwork($record['poster_path'] ?? null),
            backdrop: self::artwork($record['backdrop_path'] ?? null),
            cast: self::credits($credits['cast'] ?? null, 'cast'),
            crew: self::credits($credits['crew'] ?? null, 'crew'),
            tagline: self::text($record['tagline'] ?? null),
            runtime: is_int($record['runtime'] ?? null) ? $record['runtime'] : null,
            rating: is_numeric($record['vote_average'] ?? null) ? (float) $record['vote_average'] : null,
            originalLanguage: self::text($record['original_language'] ?? null),
            collectionId: is_int($collection['id'] ?? null) ? $collection['id'] : null,
            collectionName: self::text($collection['name'] ?? null),
            genres: self::genres($record['genres'] ?? []),
        );
    }

    /** @param array<string, mixed> $record */
    private function series(array $record, VideoCandidate $entry, string $selected): VideoTitle
    {
        $groupResponse = $record['episode_groups'] ?? $this->tmdb->lookupTvEpisodeGroups((int) $entry->id);
        if (!is_array($groupResponse)) {
            throw new UnexpectedValueException('TMDB episode groups are unavailable.');
        }
        $groups = self::rows($groupResponse['results'] ?? null);
        $orderings = [new VideoOrdering('aired', 'Aired', 'aired')];
        $eligible = [];
        foreach ($groups as $group) {
            $type = $group['type'] ?? null;
            if ($type !== 2 && $type !== 3) {
                continue;
            }
            $id = self::text($group['id'] ?? null) ?? throw new UnexpectedValueException('TMDB episode group has no ID.');
            $kind = $type === 2 ? 'absolute' : 'dvd';
            $orderings[] = new VideoOrdering($id, self::text($group['name'] ?? null) ?? ucfirst($kind), $kind);
            $eligible[$id] = $kind;
        }
        if ($selected !== 'aired' && !isset($eligible[$selected])) {
            return $this->seriesTitle($record, $entry, $orderings, []);
        }
        $absoluteIds = array_keys(array_filter($eligible, static fn (string $kind): bool => $kind === 'absolute'));
        sort($absoluteIds, SORT_STRING);
        $absoluteId = ($eligible[$selected] ?? null) === 'absolute' ? $selected : ($absoluteIds[0] ?? null);
        $absolute = [];
        $selectedGroups = null;
        if ($absoluteId !== null) {
            $absoluteGroups = self::orderedGroups($this->tmdb->lookupTvEpisodeGroup($absoluteId));
            $number = 0;
            foreach ($absoluteGroups as $group) {
                foreach ($group['episodes'] as $episode) {
                    $absolute[self::positiveId($episode['id'] ?? null)] = ++$number;
                }
            }
            if ($selected === $absoluteId) {
                $selectedGroups = $absoluteGroups;
            }
        }
        $seasons = $selected === 'aired'
            ? $this->airedSeasons($record, (int) $entry->id, $absolute)
            : $this->groupSeasons($selectedGroups ?? self::orderedGroups($this->tmdb->lookupTvEpisodeGroup($selected)), $absolute);
        if ($selected !== 'aired') {
            $seasons = $this->preserveSpecials($record, (int) $entry->id, $seasons, $absolute);
        }

        return $this->seriesTitle($record, $entry, $orderings, $seasons);
    }

    /**
     * @param array<string, mixed> $record
     * @param list<VideoOrdering> $orderings
     * @param list<VideoSeason> $seasons
     */
    private function seriesTitle(array $record, VideoCandidate $entry, array $orderings, array $seasons): VideoTitle
    {
        $credits = is_array($record['aggregate_credits'] ?? null) ? $record['aggregate_credits'] : [];

        return new VideoTitle(
            entry: $entry,
            overview: self::text($record['overview'] ?? null),
            poster: self::artwork($record['poster_path'] ?? null),
            backdrop: self::artwork($record['backdrop_path'] ?? null),
            orderings: $orderings,
            seasons: $seasons,
            cast: self::credits($credits['cast'] ?? null, 'cast'),
            crew: self::credits($credits['crew'] ?? null, 'crew'),
            tagline: self::text($record['tagline'] ?? null),
            rating: is_numeric($record['vote_average'] ?? null) ? (float) $record['vote_average'] : null,
            originalLanguage: self::text($record['original_language'] ?? null),
            genres: self::genres($record['genres'] ?? []),
        );
    }

    /**
     * @param array<string, mixed> $record
     * @param array<int, int> $absolute
     * @return list<VideoSeason>
     */
    private function airedSeasons(array $record, int $seriesId, array $absolute): array
    {
        $seasons = [];
        foreach (self::rows($record['seasons'] ?? null) as $summary) {
            $number = $summary['season_number'] ?? null;
            if (!is_int($number) || $number < 0) {
                throw new UnexpectedValueException('TMDB season has no number.');
            }
            $season = $this->tmdb->lookupTvSeason($seriesId, $number);
            if (($season['season_number'] ?? null) !== $number) {
                throw new UnexpectedValueException('TMDB season number does not match.');
            }
            $episodes = [];
            foreach (self::rows($season['episodes'] ?? null) as $episode) {
                $episodes[] = self::episode($episode, self::positiveId($episode['episode_number'] ?? null), $absolute);
            }
            usort($episodes, static fn (VideoEpisode $a, VideoEpisode $b): int => $a->number <=> $b->number);
            $seasons[] = new VideoSeason($number, self::text($season['name'] ?? null), self::text($season['overview'] ?? null), self::artwork($season['poster_path'] ?? null), $episodes);
        }
        usort($seasons, static fn (VideoSeason $a, VideoSeason $b): int => $a->number <=> $b->number);

        return $seasons;
    }

    /**
     * @param list<array<string, mixed>> $groups
     * @param array<int, int> $absolute
     * @return list<VideoSeason>
     */
    private function groupSeasons(array $groups, array $absolute): array
    {
        $seasons = [];
        $specials = [];
        foreach ($groups as $group) {
            $episodes = [];
            foreach ($group['episodes'] as $episode) {
                if (($episode['season_number'] ?? null) === 0) {
                    $specials[] = self::episode($episode, self::positiveId($episode['episode_number'] ?? null), $absolute);
                    continue;
                }
                $episodes[] = self::episode($episode, $episode['order'] + 1, $absolute);
            }
            if ($episodes !== []) {
                $seasons[] = new VideoSeason($group['order'] + 1, self::text($group['name'] ?? null), episodes: $episodes);
            }
        }
        if ($specials !== []) {
            $seasons[] = new VideoSeason(0, 'Specials', episodes: $specials);
        }

        return $seasons;
    }

    /**
     * @param array<string, mixed> $record
     * @param list<VideoSeason> $seasons
     * @param array<int, int> $absolute
     * @return list<VideoSeason>
     */
    private function preserveSpecials(array $record, int $seriesId, array $seasons, array $absolute): array
    {
        $specials = [];
        $regularSeasons = [];
        $specialSeason = null;
        foreach ($seasons as $season) {
            if ($season->number === 0) {
                $specialSeason = $season;
                foreach ($season->episodes as $episode) {
                    $specials[$episode->id] = $episode;
                }
            } else {
                $regularSeasons[] = $season;
            }
        }
        $summaries = array_filter(self::rows($record['seasons'] ?? null), static fn (array $row): bool => ($row['season_number'] ?? null) === 0)
            |> array_values(...);
        if ($summaries !== []) {
            $aired = $this->airedSeasons(['seasons' => $summaries], $seriesId, $absolute);
            $specialSeason = $aired[0];
            foreach ($specialSeason->episodes as $episode) {
                $specials[$episode->id] = $episode;
            }
        }
        if ($specialSeason !== null) {
            $episodes = array_values($specials);
            usort($episodes, static fn (VideoEpisode $a, VideoEpisode $b): int => $a->number <=> $b->number);
            array_unshift($regularSeasons, new VideoSeason(0, $specialSeason->name, $specialSeason->overview, $specialSeason->poster, $episodes));
        }

        return $regularSeasons;
    }

    /**
     * @param array<string, mixed> $record
     * @return list<array<string, mixed>>
     */
    private static function orderedGroups(array $record): array
    {
        $groups = self::rows($record['groups'] ?? null);
        foreach ($groups as &$group) {
            if (!is_int($group['order'] ?? null) || $group['order'] < 0) {
                throw new UnexpectedValueException('TMDB group has no order.');
            }
            $episodes = self::rows($group['episodes'] ?? null);
            foreach ($episodes as $episode) {
                if (!is_int($episode['order'] ?? null) || $episode['order'] < 0) {
                    throw new UnexpectedValueException('TMDB grouped episode has no order.');
                }
            }
            usort($episodes, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);
            $group['episodes'] = $episodes;
        }
        unset($group);
        usort($groups, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return $groups;
    }

    /**
     * @param array<string, mixed> $record
     * @param array<int, int> $absolute
     */
    private static function episode(array $record, int $number, array $absolute): VideoEpisode
    {
        $id = (string) self::positiveId($record['id'] ?? null);
        $name = self::text($record['name'] ?? null) ?? throw new UnexpectedValueException('TMDB episode has no name.');
        $credits = is_array($record['credits'] ?? null) ? $record['credits'] : [];

        return new VideoEpisode(
            id: $id,
            number: $number,
            title: $name,
            absoluteNumber: $absolute[(int) $id] ?? null,
            airDate: self::text($record['air_date'] ?? null),
            externalIds: ['tmdb' => $id],
            overview: self::text($record['overview'] ?? null),
            still: self::artwork($record['still_path'] ?? null),
            cast: self::credits($credits['cast'] ?? $record['guest_stars'] ?? null, 'cast'),
            crew: self::credits($credits['crew'] ?? $record['crew'] ?? null, 'crew'),
        );
    }

    /** @return list<VideoCredit>|null */
    private static function credits(mixed $rows, string $category): ?array
    {
        if (!is_array($rows) || !array_is_list($rows)) {
            return null;
        }
        $credits = [];
        foreach ($rows as $person) {
            if (!is_array($person) || !is_int($person['id'] ?? null) || $person['id'] <= 0 || self::text($person['name'] ?? null) === null) {
                return null;
            }
            $identifiers = ['tmdb_person' => (string) $person['id']];
            $external = is_array($person['external_ids'] ?? null) ? $person['external_ids'] : [];
            $imdb = self::text($external['imdb_id'] ?? $person['imdb_id'] ?? null);
            if ($imdb !== null && preg_match('/^nm[0-9]+$/D', $imdb) === 1) {
                $identifiers['imdb_name'] = $imdb;
            }
            $roles = $person[$category === 'cast' ? 'roles' : 'jobs'] ?? [$person];
            if (!is_array($roles) || !array_is_list($roles)) {
                return null;
            }
            foreach ($roles as $role) {
                if (!is_array($role)) {
                    return null;
                }
                $job = $category === 'cast' ? 'Actor' : self::text($role['job'] ?? null);
                if ($job === null) {
                    return null;
                }
                $credits[] = new VideoCredit(
                    identifiers: $identifiers,
                    name: $person['name'],
                    category: $category,
                    role: $category === 'cast' ? 'actor' : self::role($job),
                    job: $job,
                    characterName: self::text($role['character'] ?? null),
                    department: self::text($person['department'] ?? null),
                    billingOrder: is_int($person['order'] ?? null) ? $person['order'] : null,
                    providerCreditId: self::text($role['credit_id'] ?? null),
                    profileUrl: self::artwork($person['profile_path'] ?? null)?->path,
                );
            }
        }

        return $credits;
    }

    private static function role(string $job): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($job)) ?? '', '_') ?: 'crew';
    }

    /** @return list<string> */
    private static function genres(mixed $value): array
    {
        return array_map(
            static fn (array $genre): string => self::text($genre['name'] ?? null) ?? throw new UnexpectedValueException('TMDB genre has no name.'),
            self::rows($value),
        );
    }

    private static function artwork(mixed $path): ?VideoArtwork
    {
        $path = self::text($path);

        return $path === null ? null : new VideoArtwork('https://image.tmdb.org/t/p/original' . $path);
    }

    private static function year(?string $date): ?int
    {
        return $date !== null && preg_match('/^[0-9]{4}-/', $date) === 1 ? (int) substr($date, 0, 4) : null;
    }

    private static function positiveId(mixed $id): int
    {
        return is_int($id) && $id > 0 ? $id : throw new UnexpectedValueException('TMDB record has no positive identifier.');
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new UnexpectedValueException('TMDB response has no result list.');
        }
        foreach ($value as $row) {
            if (!is_array($row)) {
                throw new UnexpectedValueException('TMDB response has a malformed row.');
            }
        }

        return $value;
    }
}
