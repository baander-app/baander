<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Api\Tmdb\DTO;

use App\Catalog\Application\Port\PersonCreditInput;

final readonly class TmdbMovieDto
{
    /**
     * @param int[] $genreIds
     * @param array{name: string, character?: string, order?: int}[] $cast
     * @param list<PersonCreditInput>|null $fullCast
     * @param list<PersonCreditInput>|null $fullCrew
     * @param list<array{id: int, name: string, job: string, department: string, profilePath: ?string}> $crew
     */
    public function __construct(
        public int $id,
        public string $title,
        public ?string $originalTitle = null,
        public ?string $overview = null,
        public ?string $posterPath = null,
        public ?string $backdropPath = null,
        public ?string $releaseDate = null,
        public ?float $voteAverage = null,
        public ?int $voteCount = null,
        public ?int $runtime = null,
        public ?string $originalLanguage = null,
        public ?string $tagline = null,
        public ?string $imdbId = null,
        public array $genreIds = [],
        public ?int $belongsToCollectionId = null,
        public ?string $belongsToCollectionName = null,
        public array $cast = [],
        public array $crew = [],
        public float $popularity = 0.0,
        public bool $creditsAvailable = false,
        public ?array $fullCast = null,
        public ?array $fullCrew = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromApiResponse(array $data): self
    {
        $collection = $data['belong_to_collection'] ?? $data['belongs_to_collection'] ?? null;

        $fullCast = TmdbPersonCreditDto::partition($data['credits']['cast'] ?? null, 'cast');
        $fullCrew = TmdbPersonCreditDto::partition($data['credits']['crew'] ?? null, 'crew');
        $summaryCast = $data['credits']['cast'] ?? null;
        $cast = is_array($summaryCast) ? self::castSummary($summaryCast) : [];
        $crew = array_map(static fn(PersonCreditInput $credit): array => [
            'id' => (int) $credit->identifiers['tmdb_person'],
            'profilePath' => $credit->profileUrl === null ? null : substr($credit->profileUrl, strlen('https://image.tmdb.org/t/p/w185')),
            'name' => $credit->name,
            'job' => $credit->job,
            'department' => $credit->department ?? '',
        ], array_filter($fullCrew ?? [], static fn(PersonCreditInput $credit): bool => $credit->job === 'Director')) |> array_values(...);

        return new self(
            id: $data['id'],
            title: $data['title'] ?? '',
            originalTitle: $data['original_title'] ?? null,
            overview: $data['overview'] ?? null,
            posterPath: $data['poster_path'] ?? null,
            backdropPath: $data['backdrop_path'] ?? null,
            releaseDate: $data['release_date'] ?? null,
            voteAverage: isset($data['vote_average']) ? (float) $data['vote_average'] : null,
            voteCount: $data['vote_count'] ?? null,
            runtime: $data['runtime'] ?? null,
            originalLanguage: $data['original_language'] ?? null,
            tagline: $data['tagline'] ?? null,
            imdbId: $data['imdb_id'] ?? null,
            genreIds: $data['genre_ids'] ?? array_map(fn(array $g) => $g['id'], $data['genres'] ?? []),
            belongsToCollectionId: $collection['id'] ?? null,
            belongsToCollectionName: $collection['name'] ?? null,
            cast: $cast,
            crew: $crew,
            popularity: (float) ($data['popularity'] ?? 0.0),
            creditsAvailable: $fullCrew !== null,
            fullCast: $fullCast,
            fullCrew: $fullCrew,
        );
    }

    public function getYear(): ?int
    {
        if ($this->releaseDate === null) {
            return null;
        }

        return (int) substr($this->releaseDate, 0, 4);
    }

    /**
     * @param array<mixed> $summaryCast
     *
     * @return list<array{name: string, character: string, order: int}>
     */
    private static function castSummary(array $summaryCast): array
    {
        $members = array_filter(array_slice($summaryCast, 0, 10), is_array(...));

        return array_map(static fn (array $member): array => [
            'name' => is_string($member['name'] ?? null) ? $member['name'] : '',
            'character' => is_string($member['character'] ?? null) ? $member['character'] : '',
            'order' => is_int($member['order'] ?? null) ? $member['order'] : 0,
        ], $members) |> array_values(...);
    }
}
