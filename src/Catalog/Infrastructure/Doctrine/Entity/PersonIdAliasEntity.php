<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\PublicId;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'person_id_aliases')]
#[ORM\Index(name: 'idx_person_id_aliases_person_id', columns: ['person_id'])]
class PersonIdAliasEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'public_id')]
    private PublicId $publicId;

    #[ORM\ManyToOne(targetEntity: PersonEntity::class)]
    #[ORM\JoinColumn(name: 'person_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private PersonEntity $person;
}
