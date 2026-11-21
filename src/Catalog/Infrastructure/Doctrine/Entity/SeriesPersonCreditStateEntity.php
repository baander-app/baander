<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'series_person_credit_state')]
class SeriesPersonCreditStateEntity
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: TvSeriesEntity::class)]
    #[ORM\JoinColumn(name: 'series_id', nullable: false, onDelete: 'CASCADE')]
    public TvSeriesEntity $target;

    #[ORM\Column(name: 'cast_initialized', type: 'boolean', options: ['default' => false])]
    public bool $castInitialized = false;

    #[ORM\Column(name: 'crew_initialized', type: 'boolean', options: ['default' => false])]
    public bool $crewInitialized = false;
}
