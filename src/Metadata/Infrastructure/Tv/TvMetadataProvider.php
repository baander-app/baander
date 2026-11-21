<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Tv;

use App\Catalog\Application\Port\PersonCreditInput;
use App\Metadata\Application\DTO\TvTitleMetadata;
use App\Metadata\Application\Port\TvMetadataPortInterface;
use App\Metadata\Infrastructure\Api\Tmdb\TmdbAdapter;

final readonly class TvMetadataProvider implements TvMetadataPortInterface
{
    public function __construct(private TmdbAdapter $tmdb) {}

    public function series(int $tmdbId): ?TvTitleMetadata
    {
        $data = $this->tmdb->lookupTvSeries($tmdbId);
        if ($data === null) {
            return null;
        }

        return self::metadata($data, 'aggregate_credits', true);
    }

    public function episode(int $seriesTmdbId, int $seasonNumber, int $episodeNumber): ?TvTitleMetadata
    {
        $data = $this->tmdb->lookupTvEpisode($seriesTmdbId, $seasonNumber, $episodeNumber);
        if ($data === null) {
            return null;
        }

        return self::metadata($data, 'credits', false);
    }

    /** @param array<string, mixed> $data */
    public static function metadata(array $data, string $creditsKey, bool $aggregate): ?TvTitleMetadata
    {
        if (!is_int($data['id'] ?? null) || $data['id'] <= 0 || !is_string($data['name'] ?? null) || trim($data['name']) === '') {
            return null;
        }
        $credits = is_array($data[$creditsKey] ?? null) ? $data[$creditsKey] : [];
        $cast = self::partition($credits['cast'] ?? null, 'cast', $aggregate);
        $crew = self::partition($credits['crew'] ?? null, 'crew', $aggregate);
        if (!$aggregate && $cast !== null) {
            $guests = self::partition($credits['guest_stars'] ?? null, 'cast', false);
            $cast = $guests === null ? null : array_merge($cast, $guests);
        }

        return new TvTitleMetadata(
            tmdbId: $data['id'],
            name: trim($data['name']),
            overview: self::text($data['overview'] ?? null),
            imageUrl: self::image($data[$aggregate ? 'poster_path' : 'still_path'] ?? null),
            cast: $cast,
            crew: $crew,
            airDate: self::date($data['air_date'] ?? null),
        );
    }

    /** @return list<PersonCreditInput>|null */
    private static function partition(mixed $rows, string $category, bool $aggregate): ?array
    {
        if (!is_array($rows) || !array_is_list($rows)) {
            return null;
        }
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_int($row['id'] ?? null) || $row['id'] <= 0 || self::text($row['name'] ?? null) === null) {
                return null;
            }
            $details = $aggregate ? ($row[$category === 'cast' ? 'roles' : 'jobs'] ?? null) : [$row];
            if (!is_array($details) || !array_is_list($details) || $details === []) {
                return null;
            }
            foreach ($details as $detail) {
                if (!is_array($detail)) {
                    return null;
                }
                $job = $category === 'cast' ? 'Actor' : self::text($detail['job'] ?? null);
                if ($job === null) {
                    return null;
                }
                $result[] = new PersonCreditInput(
                    tmdbId: (string) $row['id'],
                    name: trim($row['name']),
                    category: $category,
                    role: $category === 'cast' ? 'actor' : self::role($job),
                    job: $job,
                    characterName: $category === 'cast' ? self::text($detail['character'] ?? null) : null,
                    department: self::text($row['department'] ?? null),
                    creditedName: self::text($row['original_name'] ?? null),
                    billingOrder: is_int($row['order'] ?? null) ? $row['order'] : null,
                    providerCreditId: self::text($detail['credit_id'] ?? null),
                    profileUrl: self::image($row['profile_path'] ?? null),
                );
            }
        }

        return $result;
    }

    private static function role(string $job): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($job)), '_');
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function image(mixed $value): ?string
    {
        return is_string($value) && preg_match('~^/[a-zA-Z0-9_.-]+$~D', $value) === 1
            ? 'https://image.tmdb.org/t/p/w500' . $value
            : null;
    }

    private static function date(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $value, $parts) !== 1) {
            return null;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) ? $value : null;
    }
}
