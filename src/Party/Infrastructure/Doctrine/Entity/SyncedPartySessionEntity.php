<?php

declare(strict_types=1);

namespace App\Party\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'party_sessions')]
#[ORM\UniqueConstraint(name: 'party_sessions_public_id_key', columns: ['public_id'])]
class SyncedPartySessionEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'public_id')]
    private PublicId $publicId;

    #[ORM\Column(type: 'uuid')]
    private Uuid $hostUserId;

    #[ORM\Column(type: 'uuid')]
    private Uuid $videoId;

    #[ORM\Column(type: 'uuid')]
    private Uuid $transcodeJobId;

    #[ORM\Column(type: 'integer', options: ['default' => 10])]
    private int $maxMembers;

    #[ORM\Column(type: 'text', options: ['default' => 'stopped'])]
    private string $playbackState;

    #[ORM\Column(type: 'float', options: ['default' => '0'])]
    private float $wallClockPosition;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $playbackStartedAt;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $pausedAtPosition;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isActive;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        PublicId $publicId,
        Uuid $hostUserId,
        Uuid $videoId,
        Uuid $transcodeJobId,
        int $maxMembers = 10,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new Uuid();
        $this->publicId = $publicId;
        $this->hostUserId = $hostUserId;
        $this->videoId = $videoId;
        $this->transcodeJobId = $transcodeJobId;
        $this->maxMembers = $maxMembers;
        $this->playbackState = 'stopped';
        $this->wallClockPosition = 0.0;
        $this->playbackStartedAt = null;
        $this->pausedAtPosition = null;
        $this->isActive = true;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getPublicId(): PublicId { return $this->publicId; }
    public function getHostUserId(): Uuid { return $this->hostUserId; }
    public function getVideoId(): Uuid { return $this->videoId; }
    public function getTranscodeJobId(): Uuid { return $this->transcodeJobId; }
    public function getMaxMembers(): int { return $this->maxMembers; }
    public function getPlaybackState(): string { return $this->playbackState; }
    public function getWallClockPosition(): float { return $this->wallClockPosition; }
    public function getPlaybackStartedAt(): ?\DateTimeImmutable { return $this->playbackStartedAt; }
    public function getPausedAtPosition(): ?float { return $this->pausedAtPosition; }
    public function isActive(): bool { return $this->isActive; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function setHostUserId(Uuid $userId): void { $this->hostUserId = $userId; $this->updatedAt = new \DateTimeImmutable(); }
    public function setPlaybackState(string $state): void { $this->playbackState = $state; $this->updatedAt = new \DateTimeImmutable(); }
    public function setWallClockPosition(float $position): void { $this->wallClockPosition = $position; $this->updatedAt = new \DateTimeImmutable(); }
    public function setPlaybackStartedAt(?\DateTimeImmutable $at): void { $this->playbackStartedAt = $at; $this->updatedAt = new \DateTimeImmutable(); }
    public function setPausedAtPosition(?float $position): void { $this->pausedAtPosition = $position; $this->updatedAt = new \DateTimeImmutable(); }
    public function setIsActive(bool $active): void { $this->isActive = $active; $this->updatedAt = new \DateTimeImmutable(); }
}
