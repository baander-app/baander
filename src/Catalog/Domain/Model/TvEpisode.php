<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use InvalidArgumentException;

final class TvEpisode
{
    private function __construct(private TvEpisodeState $state) {}

    public static function register(Uuid $seasonId, int $episodeNumber, int $tmdbId, string $name): self
    {
        if ($episodeNumber < 1 || $tmdbId < 1 || trim($name) === '') {
            throw new InvalidArgumentException('TV episode requires positive episode and TMDB IDs and a name.');
        }

        return new self(new TvEpisodeState(
            id: Uuid::v7(),
            publicId: new PublicId(),
            seasonId: $seasonId,
            episodeNumber: $episodeNumber,
            tmdbId: $tmdbId,
            name: trim($name),
        ));
    }

    public static function reconstitute(TvEpisodeState $state): self
    {
        return new self($state);
    }

    public function updateMetadata(int $tmdbId, string $name, ?string $overview, ?string $stillUrl, ?DateTimeImmutable $airDate): void
    {
        if ($tmdbId < 1 || trim($name) === '') {
            throw new InvalidArgumentException('TV episode requires a positive TMDB ID and a name.');
        }
        $this->state->tmdbId = $tmdbId;
        $this->state->name = trim($name);
        $this->state->overview = $overview;
        $this->state->stillUrl = $stillUrl;
        $this->state->airDate = $airDate;
    }

    public function getState(): TvEpisodeState
    {
        return $this->state;
    }
}
