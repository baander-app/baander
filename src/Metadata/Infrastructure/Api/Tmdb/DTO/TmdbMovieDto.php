<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Api\Tmdb\DTO;

final readonly class TmdbMovieDto
{
    /**
     * @param int[] $genreIds
     * @param array{name: string, character?: string, order?: int}[] $cast
     * @param array{name: string, job?: string, department?: string}[] $crew
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
    ) {
    }

    public static function fromApiResponse(array $data): self
    {
        $collection = $data['belong_to_collection'] ?? $data['belongs_to_collection'] ?? null;

        $cast = [];
        $crew = [];
        if (isset($data['credits']['cast'])) {
            $cast = array_map(fn (array $c) => [
                'name' => $c['name'] ?? '',
                'character' => $c['character'] ?? '',
                'order' => $c['order'] ?? 0,
            ], array_slice($data['credits']['cast'], 0, 10));
        }
        if (isset($data['credits']['crew'])) {
            $crew = array_map(fn (array $c) => [
                'name' => $c['name'] ?? '',
                'job' => $c['job'] ?? '',
                'department' => $c['department'] ?? '',
            ], array_filter($data['credits']['crew'], fn (array $c) => ($c['job'] ?? '') === 'Director'));
        }

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
            genreIds: $data['genre_ids'] ?? array_map(fn (array $g) => $g['id'], $data['genres'] ?? []),
            belongsToCollectionId: $collection['id'] ?? null,
            belongsToCollectionName: $collection['name'] ?? null,
            cast: $cast,
            crew: $crew,
            popularity: (float) ($data['popularity'] ?? 0.0),
        );
    }

    public function getYear(): ?int
    {
        if ($this->releaseDate === null) {
            return null;
        }

        return (int) substr($this->releaseDate, 0, 4);
    }
}
