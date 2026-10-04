<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Api\Tmdb\DTO;

final readonly class TmdbSearchResultDto
{
    /**
     * @param TmdbMovieDto[] $results
     */
    public function __construct(
        public array $results,
        public int $totalResults,
    ) {
    }

    public function isEmpty(): bool
    {
        return empty($this->results);
    }

    public function first(): ?TmdbMovieDto
    {
        return $this->results[0] ?? null;
    }
}
