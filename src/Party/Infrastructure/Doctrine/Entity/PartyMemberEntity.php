<?php

declare(strict_types=1);

namespace App\Party\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'party_members')]
#[ORM\UniqueConstraint(name: 'party_members_public_id_key', columns: ['public_id'])]
#[ORM\UniqueConstraint(name: 'party_members_user_id_session_id_key', columns: ['user_id', 'session_id'])]
class PartyMemberEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'public_id')]
    private PublicId $publicId;

    #[ORM\Column(type: 'uuid')]
    private Uuid $userId;

    #[ORM\Column(type: 'uuid')]
    private Uuid $sessionId;

    #[ORM\Column(type: 'text', options: ['default' => 'member'])]
    private string $role;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $audioProfileId;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $subtitleTrackId;

    #[ORM\Column(type: 'float', options: ['default' => '0'])]
    private float $lastSyncPosition;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastSyncAt;

    #[ORM\Column(type: 'float', options: ['default' => '0'])]
    private float $jitterCompensation;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isConnected;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $joinedAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        PublicId $publicId,
        Uuid $userId,
        Uuid $sessionId,
        string $role = 'member',
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new Uuid();
        $this->publicId = $publicId;
        $this->userId = $userId;
        $this->sessionId = $sessionId;
        $this->role = $role;
        $this->audioProfileId = null;
        $this->subtitleTrackId = null;
        $this->lastSyncPosition = 0.0;
        $this->lastSyncAt = null;
        $this->jitterCompensation = 0.0;
        $this->isConnected = true;
        $this->joinedAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getPublicId(): PublicId { return $this->publicId; }
    public function getUserId(): Uuid { return $this->userId; }
    public function getSessionId(): Uuid { return $this->sessionId; }
    public function getRole(): string { return $this->role; }
    public function getAudioProfileId(): ?string { return $this->audioProfileId; }
    public function getSubtitleTrackId(): ?string { return $this->subtitleTrackId; }
    public function getLastSyncPosition(): float { return $this->lastSyncPosition; }
    public function getLastSyncAt(): ?\DateTimeImmutable { return $this->lastSyncAt; }
    public function getJitterCompensation(): float { return $this->jitterCompensation; }
    public function isConnected(): bool { return $this->isConnected; }
    public function getJoinedAt(): \DateTimeImmutable { return $this->joinedAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function setRole(string $role): void { $this->role = $role; $this->updatedAt = new \DateTimeImmutable(); }
    public function setAudioProfileId(?string $id): void { $this->audioProfileId = $id; $this->updatedAt = new \DateTimeImmutable(); }
    public function setSubtitleTrackId(?string $id): void { $this->subtitleTrackId = $id; $this->updatedAt = new \DateTimeImmutable(); }
    public function setLastSyncPosition(float $position): void { $this->lastSyncPosition = $position; $this->updatedAt = new \DateTimeImmutable(); }
    public function setLastSyncAt(?\DateTimeImmutable $at): void { $this->lastSyncAt = $at; $this->updatedAt = new \DateTimeImmutable(); }
    public function setJitterCompensation(float $jitter): void { $this->jitterCompensation = $jitter; $this->updatedAt = new \DateTimeImmutable(); }
    public function setIsConnected(bool $connected): void { $this->isConnected = $connected; $this->updatedAt = new \DateTimeImmutable(); }
}
