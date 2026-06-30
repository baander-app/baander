<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Api\Tmdb\DTO;

/** @param array{id: int, title: string, poster_path?: string}[] $parts */
final readonly class TmdbCollectionDto
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $overview = null,
        public ?string $posterPath = null,
        public ?string $backdropPath = null,
        public array $parts = [],
    ) {
    }

    public static function fromApiResponse(array $data): self
    {
        return new self(
            id: $data['id'],
            name: $data['name'] ?? '',
            overview: $data['overview'] ?? null,
            posterPath: $data['poster_path'] ?? null,
            backdropPath: $data['backdrop_path'] ?? null,
            parts: $data['parts'] ?? [],
        );
    }
}
