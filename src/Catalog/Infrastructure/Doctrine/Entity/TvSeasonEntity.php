<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Entity;

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

    #[ORM\Column(name: 'season_number', type: 'integer')]
    public int $seasonNumber;

    public function __construct(TvSeriesEntity $series, int $seasonNumber)
    {
        $this->id = Uuid::v7();
        $this->publicId = new PublicId();
        $this->series = $series;
        $this->seasonNumber = $seasonNumber;
    }
}
