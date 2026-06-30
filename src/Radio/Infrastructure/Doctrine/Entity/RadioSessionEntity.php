<?php

declare(strict_types=1);

namespace App\Radio\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'radio_sessions')]
#[ORM\UniqueConstraint(name: 'radio_sessions_user_id_key', columns: ['user_id'])]
class RadioSessionEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $userId;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $activeStationId = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $activeStreamUrl = null;

    #[ORM\Column(type: 'text', options: ['default' => 'stopped'])]
    private string $state = 'stopped';

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Uuid $id,
        Uuid $userId,
    ) {
        $this->id = $id;
        $this->userId = $userId;
        $this->state = 'stopped';
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getActiveStationId(): ?Uuid
    {
        return $this->activeStationId;
    }

    public function setActiveStationId(?Uuid $stationId): void
    {
        $this->activeStationId = $stationId;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getActiveStreamUrl(): ?string
    {
        return $this->activeStreamUrl;
    }

    public function setActiveStreamUrl(?string $streamUrl): void
    {
        $this->activeStreamUrl = $streamUrl;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function setState(string $state): void
    {
        $this->state = $state;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }
}
