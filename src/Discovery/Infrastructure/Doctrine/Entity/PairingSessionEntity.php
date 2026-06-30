<?php

declare(strict_types=1);

namespace App\Discovery\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'pairing_sessions')]
#[ORM\UniqueConstraint(name: 'pairing_sessions_public_id_key', columns: ['public_id'])]
#[ORM\UniqueConstraint(name: 'pairing_sessions_pairing_code_key', columns: ['pairing_code'])]
class PairingSessionEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'public_id')]
    private PublicId $publicId;

    #[ORM\Column(type: 'uuid')]
    private Uuid $serverId;

    #[ORM\Column(type: 'public_id')]
    private PublicId $serverPublicId;

    #[ORM\Column(type: 'text')]
    private string $serverUrl;

    #[ORM\Column(type: 'text')]
    private string $serverName;

    #[ORM\Column(type: 'text')]
    private string $pairingCode;

    #[ORM\Column(type: 'text')]
    private string $method;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiredAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        PublicId $publicId,
        Uuid $serverId,
        PublicId $serverPublicId,
        string $serverUrl,
        string $serverName,
        string $pairingCode,
        string $method,
        ?\DateTimeImmutable $expiresAt = null,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new Uuid();
        $this->publicId = $publicId;
        $this->serverId = $serverId;
        $this->serverPublicId = $serverPublicId;
        $this->serverUrl = $serverUrl;
        $this->serverName = $serverName;
        $this->pairingCode = $pairingCode;
        $this->method = $method;
        $this->expiresAt = $expiresAt;
        $this->completedAt = null;
        $this->expiredAt = null;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getPublicId(): PublicId { return $this->publicId; }
    public function getServerId(): Uuid { return $this->serverId; }
    public function getServerPublicId(): PublicId { return $this->serverPublicId; }
    public function getServerUrl(): string { return $this->serverUrl; }
    public function getServerName(): string { return $this->serverName; }
    public function getPairingCode(): string { return $this->pairingCode; }
    public function getMethod(): string { return $this->method; }
    public function getExpiresAt(): ?\DateTimeImmutable { return $this->expiresAt; }
    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
    public function getExpiredAt(): ?\DateTimeImmutable { return $this->expiredAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function setCompletedAt(?\DateTimeImmutable $at): void { $this->completedAt = $at; $this->updatedAt = new \DateTimeImmutable(); }
    public function setExpiredAt(?\DateTimeImmutable $at): void { $this->expiredAt = $at; $this->updatedAt = new \DateTimeImmutable(); }
}
