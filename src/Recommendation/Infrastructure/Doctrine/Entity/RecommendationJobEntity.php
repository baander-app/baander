<?php

declare(strict_types=1);

namespace App\Recommendation\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'recommendation_jobs')]
#[ORM\UniqueConstraint(name: 'recommendation_jobs_public_id_idx', columns: ['public_id'])]
#[ORM\HasLifecycleCallbacks]
class RecommendationJobEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'public_id')]
    private PublicId $publicId;

    #[ORM\Column(type: 'boolean')]
    private bool $isFull;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $userId = null;

    #[ORM\Column(type: 'text')]
    private string $status;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $totalSongs;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $completedSongs;

    #[ORM\Column(type: 'text', options: ['default' => ''])]
    private string $currentStrategy;

    #[ORM\Column(type: 'json', options: ['jsonb' => true, 'default' => '{}'])]
    private array $strategyCounts;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $failReason = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(type: 'json', options: ['jsonb' => true, 'default' => '{}'])]
    private array $metadata = [];

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $originalJobId = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        if (!isset($this->createdAt)) {
            $this->createdAt = new \DateTimeImmutable();
        }
        if (!isset($this->updatedAt)) {
            $this->updatedAt = new \DateTimeImmutable();
        }
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function __construct(
        PublicId $publicId,
        bool $isFull,
        string $status = 'pending',
        ?Uuid $userId = null,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new Uuid();
        $this->publicId = $publicId;
        $this->isFull = $isFull;
        $this->status = $status;
        $this->userId = $userId;
        $this->totalSongs = 0;
        $this->completedSongs = 0;
        $this->currentStrategy = '';
        $this->strategyCounts = [];
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPublicId(): PublicId
    {
        return $this->publicId;
    }

    public function isFull(): bool
    {
        return $this->isFull;
    }

    public function getUserId(): ?Uuid
    {
        return $this->userId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getTotalSongs(): int
    {
        return $this->totalSongs;
    }

    public function setTotalSongs(int $totalSongs): void
    {
        $this->totalSongs = $totalSongs;
    }

    public function getCompletedSongs(): int
    {
        return $this->completedSongs;
    }

    public function setCompletedSongs(int $completedSongs): void
    {
        $this->completedSongs = $completedSongs;
    }

    public function getCurrentStrategy(): string
    {
        return $this->currentStrategy;
    }

    public function setCurrentStrategy(string $currentStrategy): void
    {
        $this->currentStrategy = $currentStrategy;
    }

    public function getStrategyCounts(): array
    {
        return $this->strategyCounts;
    }

    public function setStrategyCounts(array $strategyCounts): void
    {
        $this->strategyCounts = $strategyCounts;
    }

    public function getFailReason(): ?string
    {
        return $this->failReason;
    }

    public function setFailReason(?string $failReason): void
    {
        $this->failReason = $failReason;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): void
    {
        $this->startedAt = $startedAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): void
    {
        $this->completedAt = $completedAt;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function setMetadata(array $metadata): void
    {
        $this->metadata = $metadata;
    }

    public function getOriginalJobId(): ?Uuid
    {
        return $this->originalJobId;
    }

    public function setOriginalJobId(?Uuid $originalJobId): void
    {
        $this->originalJobId = $originalJobId;
    }
}
