<?php

declare(strict_types=1);

namespace App\Radio\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'starred_stations')]
#[ORM\UniqueConstraint(name: 'starred_stations_user_id_station_id_key', columns: ['user_id', 'station_id'])]
class StarredStationEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $userId;

    #[ORM\Column(type: 'uuid')]
    private Uuid $stationId;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $starredAt;

    public function __construct(
        Uuid $id,
        Uuid $userId,
        Uuid $stationId,
    ) {
        $this->id = $id;
        $this->userId = $userId;
        $this->stationId = $stationId;
        $this->starredAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getStationId(): Uuid
    {
        return $this->stationId;
    }

    public function getStarredAt(): \DateTimeImmutable
    {
        return $this->starredAt;
    }
}
