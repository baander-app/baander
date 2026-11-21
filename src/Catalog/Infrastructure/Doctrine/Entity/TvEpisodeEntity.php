<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Entity;

use App\Catalog\Domain\Model\TvEpisodeState;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'tv_episodes')]
#[ORM\UniqueConstraint(name: 'uniq_tv_episodes_public_id', columns: ['public_id'])]
#[ORM\UniqueConstraint(name: 'uniq_tv_episodes_season_id_episode_number', columns: ['season_id', 'episode_number'])]
class TvEpisodeEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    public Uuid $id;

    #[ORM\Column(type: 'public_id')]
    public PublicId $publicId;

    #[ORM\ManyToOne(targetEntity: TvSeasonEntity::class)]
    #[ORM\JoinColumn(name: 'season_id', nullable: false, onDelete: 'CASCADE')]
    public TvSeasonEntity $season;

    #[ORM\Column(name: 'episode_number', type: 'integer')]
    public int $episodeNumber;

    #[ORM\Column(name: 'tmdb_id', type: 'integer')]
    public int $tmdbId;

    #[ORM\Column(type: 'text')]
    public string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $overview = null;

    #[ORM\Column(name: 'still_url', type: 'text', nullable: true)]
    public ?string $stillUrl = null;

    #[ORM\Column(name: 'air_date', type: 'date_immutable', nullable: true)]
    public ?DateTimeImmutable $airDate = null;

    public function __construct(TvSeasonEntity $season, TvEpisodeState $state)
    {
        $this->id = $state->id;
        $this->publicId = $state->publicId;
        $this->season = $season;
        $this->episodeNumber = $state->episodeNumber;
        $this->apply($state);
    }
    public function state(): TvEpisodeState
    {
        return new TvEpisodeState(
            id: $this->id,
            publicId: $this->publicId,
            seasonId: $this->season->id,
            episodeNumber: $this->episodeNumber,
            tmdbId: $this->tmdbId,
            name: $this->name,
            overview: $this->overview,
            stillUrl: $this->stillUrl,
            airDate: $this->airDate,
        );
    }

    public function apply(TvEpisodeState $state): void
    {
        $this->tmdbId = $state->tmdbId;
        $this->name = $state->name;
        $this->overview = $state->overview;
        $this->stillUrl = $state->stillUrl;
        $this->airDate = $state->airDate;
    }
}
