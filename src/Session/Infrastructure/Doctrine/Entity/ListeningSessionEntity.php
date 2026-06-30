<?php

declare(strict_types=1);

namespace App\Session\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'listening_sessions')]
#[ORM\UniqueConstraint(name: 'listening_sessions_user_id_key', columns: ['user_id'])]
class ListeningSessionEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $userId;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $activeDeviceId = null;

    #[ORM\Column(type: 'json', options: ['jsonb' => true, 'default' => '[]'])]
    private array $queue = [];

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $currentTrackIndex = 0;

    #[ORM\Column(type: 'float', options: ['default' => '0'])]
    private float $position = 0.0;

    #[ORM\Column(type: 'text', options: ['default' => 'stopped'])]
    private string $playbackState = 'stopped';

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    public function __construct(
        Uuid $userId,
        array $queue = [],
        int $currentTrackIndex = 0,
        float $position = 0.0,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new Uuid();
        $this->userId = $userId;
        $this->queue = $queue;
        $this->currentTrackIndex = $currentTrackIndex;
        $this->position = $position;
        $this->playbackState = 'stopped';
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

    public function getActiveDeviceId(): ?Uuid
    {
        return $this->activeDeviceId;
    }

    public function setActiveDeviceId(?Uuid $deviceId): void
    {
        $this->activeDeviceId = $deviceId;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getQueue(): array
    {
        return $this->queue;
    }

    public function setQueue(array $queue): void
    {
        $this->queue = $queue;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getCurrentTrackIndex(): int
    {
        return $this->currentTrackIndex;
    }

    public function setCurrentTrackIndex(int $index): void
    {
        $this->currentTrackIndex = $index;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getPosition(): float
    {
        return $this->position;
    }

    public function setPosition(float $position): void
    {
        $this->position = $position;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getPlaybackState(): string
    {
        return $this->playbackState;
    }

    public function setPlaybackState(string $state): void
    {
        $this->playbackState = $state;
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

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function setLastUsedAt(?\DateTimeImmutable $lastUsedAt): void
    {
        $this->lastUsedAt = $lastUsedAt;
    }

    public function markUsed(): void
    {
        $this->lastUsedAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }
}
