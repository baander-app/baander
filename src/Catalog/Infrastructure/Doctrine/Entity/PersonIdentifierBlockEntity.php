<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'person_identifier_blocks')]
class PersonIdentifierBlockEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'text')]
    private string $namespace;

    #[ORM\Id]
    #[ORM\Column(type: 'text')]
    private string $externalId;
}
