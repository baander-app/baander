<?php

declare(strict_types=1);

namespace App\Activity\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'watched_mark_moves')]
class WatchedMarkMoveEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $operationId;
}
