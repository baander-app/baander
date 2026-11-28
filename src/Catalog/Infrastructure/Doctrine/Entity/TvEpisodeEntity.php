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
#[ORM\Index(name: 'idx_tv_episodes_series_id', columns: ['series_id'])]
class TvEpisodeEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    public Uuid $id;

    #[ORM\Column(type: 'public_id')]
    public PublicId $publicId;

    #[ORM\ManyToOne(targetEntity: TvSeriesEntity::class)]
    #[ORM\JoinColumn(name: 'series_id', nullable: false, onDelete: 'CASCADE')]
    public TvSeriesEntity $series;

    #[ORM\ManyToOne(targetEntity: TvSeasonEntity::class)]
    #[ORM\JoinColumn(name: 'season_id', nullable: true, onDelete: 'CASCADE')]
    public ?TvSeasonEntity $season = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    public ?int $episodeNumber = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    public ?int $absoluteNumber = null;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $providerId = null;

    /** @var array<string, string> */
    #[ORM\Column(type: 'json', options: ['jsonb' => true, 'default' => '[]'])]
    public array $externalIds = [];

    #[ORM\Column(type: 'text')]
    public string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $overview = null;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $stillUrl = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    public ?DateTimeImmutable $airDate = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    public bool $retired = false;

    /** @var array<string, string|int|null> */
    #[ORM\Column(type: 'json', options: ['jsonb' => true, 'default' => '[]'])]
    public array $providerFields = [];

    /** @var list<string> */
    #[ORM\Column(type: 'json', options: ['jsonb' => true, 'default' => '[]'])]
    public array $lockedFields = [];

    public function __construct(TvSeriesEntity $series, ?TvSeasonEntity $season, TvEpisodeState $state)
    {
        $this->id = $state->id;
        $this->publicId = $state->publicId;
        $this->series = $series;
        $this->season = $season;
        $this->apply($state);
    }

    public function state(): TvEpisodeState
    {
        return new TvEpisodeState(
            id: $this->id,
            publicId: $this->publicId,
            seriesId: $this->series->id,
            seasonId: $this->season?->id,
            episodeNumber: $this->episodeNumber,
            name: $this->name,
            absoluteNumber: $this->absoluteNumber,
            airDate: $this->airDate,
            providerId: $this->providerId,
            externalIds: $this->externalIds,
            overview: $this->overview,
            stillUrl: $this->stillUrl,
            retired: $this->retired,
            providerFields: $this->providerFields,
            lockedFields: $this->lockedFields,
        );
    }

    public function apply(TvEpisodeState $state): void
    {
        $this->providerFields = $state->providerFields;
        $this->lockedFields = $state->lockedFields;
        $this->episodeNumber = $state->episodeNumber;
        $this->absoluteNumber = $state->absoluteNumber;
        $this->providerId = $state->providerId;
        $this->externalIds = $state->externalIds;
        $this->name = $state->name;
        $this->overview = $state->overview;
        $this->stillUrl = $state->stillUrl;
        $this->airDate = $state->airDate;
        $this->retired = $state->retired;
    }
}
