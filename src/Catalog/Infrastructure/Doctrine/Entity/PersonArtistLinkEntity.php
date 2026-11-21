<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'person_artist_links')]
#[ORM\Index(name: 'idx_person_artist_links_person_id', columns: ['person_id'])]
class PersonArtistLinkEntity
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: ArtistEntity::class)]
    #[ORM\JoinColumn(name: 'artist_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ArtistEntity $artist;

    #[ORM\ManyToOne(targetEntity: PersonEntity::class)]
    #[ORM\JoinColumn(name: 'person_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?PersonEntity $person = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $artistMbid = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $managedByOperator = false;
}
