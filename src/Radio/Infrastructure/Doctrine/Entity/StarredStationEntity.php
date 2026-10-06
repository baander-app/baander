<?php

declare(strict_types=1);

namespace App\Radio\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'starred_stations')]
#[ORM\UniqueConstraint(name: 'starred_stations_user_id_station_id_key', columns: ['user_id', 'station_id'])]
#[ORM\Index(name: 'idx_starred_stations_user', columns: ['user_id'])]
class StarredStationEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(name: 'user_id', type: 'uuid')]
    private Uuid $userId;

    #[ORM\ManyToOne(targetEntity: RadioStationEntity::class)]
    #[ORM\JoinColumn(name: 'station_id', referencedColumnName: 'id', nullable: false)]
    private RadioStationEntity $station;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $starredAt;

    public function __construct(
        Uuid $id,
        Uuid $userId,
        RadioStationEntity $station,
        \DateTimeImmutable $starredAt,
    ) {
        $this->id = $id;
        $this->userId = $userId;
        $this->station = $station;
        $this->starredAt = $starredAt;
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
        return $this->station->getId();
    }

    public function getStarredAt(): \DateTimeImmutable
    {
        return $this->starredAt;
    }
}
