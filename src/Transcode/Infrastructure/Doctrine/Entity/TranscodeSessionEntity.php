<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'transcode_sessions')]
#[ORM\Index(name: 'idx_transcode_sessions_job_id', columns: ['job_id'])]
#[ORM\UniqueConstraint(name: 'transcode_sessions_public_id_key', columns: ['public_id'])]
class TranscodeSessionEntity
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
    private Uuid $videoId;

    #[ORM\Column(type: 'text', options: ['default' => 'pending'])]
    private string $state;

    #[ORM\Column(type: 'text', options: ['default' => 'normal'])]
    private string $priority;

    #[ORM\Column(type: 'json', options: ['jsonb' => true, 'default' => '{}'])]
    private array $audioProfile;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $currentSegmentIndex;

    #[ORM\Column(type: 'float', options: ['default' => '0'])]
    private float $wallClockOffset;

    #[ORM\Column(type: 'json', options: ['jsonb' => true, 'default' => '{}'])]
    private array $metrics;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne(targetEntity: TranscodeJobEntity::class)]
    #[ORM\JoinColumn(name: 'job_id', referencedColumnName: 'id', onDelete: 'CASCADE', nullable: true)]
    private ?TranscodeJobEntity $job = null;

    public function __construct(
        PublicId $publicId,
        Uuid $userId,
        TranscodeJobEntity $job,
        Uuid $videoId,
        string $state = 'pending',
        string $priority = 'normal',
        array $audioProfile = [],
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? new Uuid();
        $this->publicId = $publicId;
        $this->userId = $userId;
        $this->job = $job;
        $this->videoId = $videoId;
        $this->state = $state;
        $this->priority = $priority;
        $this->audioProfile = $audioProfile;
        $this->currentSegmentIndex = 0;
        $this->wallClockOffset = 0.0;
        $this->metrics = [];
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getPublicId(): PublicId { return $this->publicId; }
    public function getUserId(): Uuid { return $this->userId; }
    public function getJobId(): ?Uuid { return $this->job?->getId(); }
    public function getVideoId(): Uuid { return $this->videoId; }
    public function getState(): string { return $this->state; }
    public function getPriority(): string { return $this->priority; }
    public function getAudioProfile(): array { return $this->audioProfile; }
    public function getCurrentSegmentIndex(): int { return $this->currentSegmentIndex; }
    public function getWallClockOffset(): float { return $this->wallClockOffset; }
    public function getMetrics(): array { return $this->metrics; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function getJob(): ?TranscodeJobEntity { return $this->job; }

    public function setState(string $state): void
    {
        $this->state = $state;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setPriority(string $priority): void
    {
        $this->priority = $priority;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setAudioProfile(array $profile): void
    {
        $this->audioProfile = $profile;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setCurrentSegmentIndex(int $index): void
    {
        $this->currentSegmentIndex = $index;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setWallClockOffset(float $offset): void
    {
        $this->wallClockOffset = $offset;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setMetrics(array $metrics): void
    {
        $this->metrics = $metrics;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
