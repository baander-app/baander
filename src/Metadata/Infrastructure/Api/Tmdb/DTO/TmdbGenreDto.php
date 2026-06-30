<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Api\Tmdb\DTO;

final readonly class TmdbGenreDto
{
    public function __construct(
        public int $id,
        public string $name,
    ) {
    }
}
