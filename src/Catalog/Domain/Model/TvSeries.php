<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use InvalidArgumentException;

final class TvSeries
{
    private function __construct(private TvSeriesState $state) {}

    public static function register(Uuid $libraryId, int $tmdbId, string $name): self
    {
        if ($tmdbId < 1 || trim($name) === '') {
            throw new InvalidArgumentException('TV series requires a positive TMDB ID and a name.');
        }

        return new self(new TvSeriesState(
            id: Uuid::v7(),
            publicId: new PublicId(),
            libraryId: $libraryId,
            tmdbId: $tmdbId,
            name: trim($name),
        ));
    }

    public static function reconstitute(TvSeriesState $state): self
    {
        return new self($state);
    }

    public function updateMetadata(string $name, ?string $overview, ?string $posterUrl): void
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('TV series name cannot be empty.');
        }
        $this->state->name = trim($name);
        $this->state->overview = $overview;
        $this->state->posterUrl = $posterUrl;
    }

    public function getState(): TvSeriesState
    {
        return $this->state;
    }
}
