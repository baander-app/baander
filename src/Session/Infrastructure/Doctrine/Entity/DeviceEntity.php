<?php

declare(strict_types=1);

namespace App\Session\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'devices')]
class DeviceEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $userId;

    #[ORM\Column(type: 'uuid')]
    private Uuid $deviceId;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $name = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Uuid $userId,
        Uuid $deviceId,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new Uuid();
        $this->userId = $userId;
        $this->deviceId = $deviceId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getUserId(): Uuid { return $this->userId; }
    public function getDeviceId(): Uuid { return $this->deviceId; }
    public function getName(): ?string { return $this->name; }
    public function setName(?string $name): void { $this->name = $name; }
    public function getLastSeenAt(): ?\DateTimeImmutable { return $this->lastSeenAt; }
    public function setLastSeenAt(?\DateTimeImmutable $lastSeenAt): void { $this->lastSeenAt = $lastSeenAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
