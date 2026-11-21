<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'person_external_ids')]
#[ORM\Index(name: 'idx_person_external_ids_person_id', columns: ['person_id'])]
class PersonExternalIdEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'text')]
    private string $namespace;

    #[ORM\Id]
    #[ORM\Column(type: 'text')]
    private string $externalId;

    #[ORM\ManyToOne(targetEntity: PersonEntity::class)]
    #[ORM\JoinColumn(name: 'person_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private PersonEntity $person;

    #[ORM\Column(type: 'text')]
    private string $source;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $profileFetchedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $profileClaimedUntil = null;

    public function __construct(PersonEntity $person, string $namespace, string $externalId, string $source)
    {
        $this->person = $person;
        $this->namespace = $namespace;
        $this->externalId = $externalId;
        $this->source = $source;
    }

    public function getPerson(): PersonEntity
    {
        return $this->person;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }
}
