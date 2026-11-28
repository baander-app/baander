<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use InvalidArgumentException;

final class TvSeason
{
    private function __construct(private TvSeasonState $state) {}

    public static function create(Uuid $seriesId, int $seasonNumber): self
    {
        if ($seasonNumber < 0) {
            throw new InvalidArgumentException('TV season number cannot be negative.');
        }

        return new self(new TvSeasonState(Uuid::v7(), new PublicId(), $seriesId, $seasonNumber));
    }

    public static function reconstitute(TvSeasonState $state): self
    {
        return new self($state);
    }

    public function updateMetadata(?string $name, ?string $overview, ?string $posterUrl): void
    {
        $this->state->name = $name;
        $this->state->overview = $overview;
        $this->state->posterUrl = $posterUrl;
    }

    public function getId(): Uuid { return $this->state->id; }
    public function getPublicId(): PublicId { return $this->state->publicId; }
    public function getSeriesId(): Uuid { return $this->state->seriesId; }
    public function getSeasonNumber(): int { return $this->state->seasonNumber; }
    public function getState(): TvSeasonState { return $this->state; }
}
