<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Api\Tmdb\DTO;

final readonly class TmdbCollectionDto
{
    /**
     * @param array<array-key, array{id: int, title: string, poster_path?: string|null}> $parts
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?string $overview = null,
        public ?string $posterPath = null,
        public ?string $backdropPath = null,
        public array $parts = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
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
