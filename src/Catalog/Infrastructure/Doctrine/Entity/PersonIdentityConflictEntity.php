<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'person_identity_conflicts')]
class PersonIdentityConflictEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'text')]
    private string $claimKey;

    /** @var array<string, string> */
    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    private array $claims;

    /** @var list<string> */
    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    private array $owners;

    #[ORM\Column(type: 'text')]
    private string $source;

    #[ORM\Column(type: 'datetime_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private DateTimeImmutable $observedAt;
}
