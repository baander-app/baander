<?php

declare(strict_types=1);

namespace App\Session\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\Model\PublicId;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'listening_sessions')]
#[ORM\Index(name: 'idx_playback_selected_destination', columns: ['selected_destination_id', 'user_id'])]
#[ORM\Index(name: 'idx_playback_active_incarnation', columns: ['active_incarnation_id', 'user_id'])]
#[ORM\UniqueConstraint(name: 'uniq_listening_sessions_public_id', columns: ['public_id'])]
#[ORM\UniqueConstraint(name: 'uniq_listening_sessions_user_id', columns: ['user_id'])]
#[ORM\UniqueConstraint(name: 'uniq_playback_session_owner', columns: ['id', 'user_id'])]
class ListeningSessionEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(name: 'public_id', type: 'text')]
    private string $publicId;

    #[ORM\Column(name: 'user_id', type: 'uuid')]
    private Uuid $userId;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $activeDeviceId = null;

    /**
     * @var array<array-key, mixed>
     */
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

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $revision = 0;

    /** @var array<string,mixed>|null */
    #[ORM\Column(type: 'json', nullable: true, options: ['jsonb' => true])]
    private ?array $media = null;

    #[ORM\Column(type: 'text', options: ['default' => 'stopped'])]
    private string $desiredState = 'stopped';

    #[ORM\Column(type: 'text', options: ['default' => 'unknown'])]
    private string $observedState = 'unknown';

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $selectedDestinationId = null;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $activeIncarnationId = null;

    /**
     * @param array<array-key, mixed> $queue
     */
    public function __construct(
        Uuid $userId,
        array $queue = [],
        int $currentTrackIndex = 0,
        float $position = 0.0,
        ?Uuid $id = null,
        ?PublicId $publicId = null,
    ) {
        $this->id = $id ?? new Uuid();
        $this->publicId = ($publicId ?? new PublicId())->toString();
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

    public function getPublicId(): PublicId
    {
        return PublicId::fromString($this->publicId);
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

    /**
     * @return array<array-key, mixed>
     */
    public function getQueue(): array
    {
        return $this->queue;
    }

    /**
     * @param array<array-key, mixed> $queue
     */
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
    public function getRevision(): int
    {
        return $this->revision;
    }
    public function setRevision(int $value): void
    {
        $this->revision = $value;
    }
    /** @return array<string,mixed>|null */
    public function getMedia(): ?array
    {
        return $this->media;
    }
    /** @param array<string,mixed>|null $value */
    public function setMedia(?array $value): void
    {
        $this->media = $value;
    }
    public function getDesiredState(): string
    {
        return $this->desiredState;
    }
    public function setDesiredState(string $value): void
    {
        $this->desiredState = $value;
    }
    public function getObservedState(): string
    {
        return $this->observedState;
    }
    public function setObservedState(string $value): void
    {
        $this->observedState = $value;
    }
    public function getSelectedDestinationId(): ?Uuid
    {
        return $this->selectedDestinationId;
    }
    public function setSelectedDestinationId(?Uuid $value): void
    {
        $this->selectedDestinationId = $value;
    }
    public function getActiveIncarnationId(): ?Uuid
    {
        return $this->activeIncarnationId;
    }
    public function setActiveIncarnationId(?Uuid $value): void
    {
        $this->activeIncarnationId = $value;
    }

}
