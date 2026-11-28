<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Entity;

use App\Catalog\Domain\Model\TvSeasonState;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'tv_seasons')]
#[ORM\UniqueConstraint(name: 'uniq_tv_seasons_public_id', columns: ['public_id'])]
#[ORM\UniqueConstraint(name: 'uniq_tv_seasons_series_id_season_number', columns: ['series_id', 'season_number'])]
class TvSeasonEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    public Uuid $id;

    #[ORM\Column(type: 'public_id')]
    public PublicId $publicId;

    #[ORM\ManyToOne(targetEntity: TvSeriesEntity::class)]
    #[ORM\JoinColumn(name: 'series_id', nullable: false, onDelete: 'CASCADE')]
    public TvSeriesEntity $series;

    #[ORM\Column(type: 'integer')]
    public int $seasonNumber;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $name = null;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $overview = null;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $posterUrl = null;

    /** @var array<string, string|int|null> */
    #[ORM\Column(type: 'json', options: ['jsonb' => true, 'default' => '[]'])]
    public array $providerFields = [];

    /** @var list<string> */
    #[ORM\Column(type: 'json', options: ['jsonb' => true, 'default' => '[]'])]
    public array $lockedFields = [];

    public function __construct(TvSeriesEntity $series, TvSeasonState $state)
    {
        $this->id = $state->id;
        $this->publicId = $state->publicId;
        $this->series = $series;
        $this->apply($state);
    }

    public function state(): TvSeasonState
    {
        return new TvSeasonState(
            id: $this->id,
            publicId: $this->publicId,
            seriesId: $this->series->id,
            seasonNumber: $this->seasonNumber,
            name: $this->name,
            overview: $this->overview,
            posterUrl: $this->posterUrl,
            providerFields: $this->providerFields,
            lockedFields: $this->lockedFields,
        );
    }

    public function apply(TvSeasonState $state): void
    {
        $this->providerFields = $state->providerFields;
        $this->lockedFields = $state->lockedFields;
        $this->seasonNumber = $state->seasonNumber;
        $this->name = $state->name;
        $this->overview = $state->overview;
        $this->posterUrl = $state->posterUrl;
    }
}
