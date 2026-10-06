<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Doctrine\Entity;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'transcode_sessions')]
#[ORM\Index(name: 'idx_transcode_sessions_job_id', columns: ['job_id'])]
#[ORM\Index(name: 'idx_transcode_sessions_state', columns: ['state'])]
#[ORM\Index(name: 'idx_transcode_sessions_user_id', columns: ['user_id'])]
#[ORM\UniqueConstraint(name: 'transcode_sessions_public_id_key', columns: ['public_id'])]
class TranscodeSessionEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(type: 'public_id')]
    private PublicId $publicId;

    #[ORM\Column(name: 'user_id', type: 'uuid')]
    private Uuid $userId;

    #[ORM\Column(type: 'uuid')]
    private Uuid $videoId;

    #[ORM\Column(type: 'text', options: ['default' => 'pending'])]
    private string $state;

    #[ORM\Column(type: 'text', options: ['default' => 'normal'])]
    private string $priority;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json', options: ['jsonb' => true, 'default' => '{}'])]
    private array $audioProfile;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $currentSegmentIndex;

    #[ORM\Column(type: 'float', options: ['default' => '0'])]
    private float $wallClockOffset;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json', options: ['jsonb' => true, 'default' => '{}'])]
    private array $metrics;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne(targetEntity: TranscodeJobEntity::class)]
    #[ORM\JoinColumn(name: 'job_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private TranscodeJobEntity $job;

    /**
     * @param array<string, mixed> $audioProfile
     */
    public function __construct(
        PublicId $publicId,
        Uuid $userId,
        TranscodeJobEntity $job,
        Uuid $videoId,
        string $state,
        string $priority,
        array $audioProfile,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $updatedAt,
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
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    public function getId(): Uuid { return $this->id; }
    public function getPublicId(): PublicId { return $this->publicId; }
    public function getUserId(): Uuid { return $this->userId; }
    public function getJobId(): Uuid { return $this->job->getId(); }
    public function getVideoId(): Uuid { return $this->videoId; }
    public function getState(): string { return $this->state; }
    public function getPriority(): string { return $this->priority; }
    /**
     * @return array<string, mixed>
     */
    public function getAudioProfile(): array
    {
        return $this->audioProfile;
    }
    public function getCurrentSegmentIndex(): int { return $this->currentSegmentIndex; }
    public function getWallClockOffset(): float { return $this->wallClockOffset; }
    /**
     * @return array<string, mixed>
     */
    public function getMetrics(): array
    {
        return $this->metrics;
    }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function getJob(): TranscodeJobEntity { return $this->job; }

    public function setState(string $state): void
    {
        $this->state = $state;
    }

    public function setPriority(string $priority): void
    {
        $this->priority = $priority;
    }

    /**
     * @param array<string, mixed> $profile
     */
    public function setAudioProfile(array $profile): void
    {
        $this->audioProfile = $profile;
    }

    public function setCurrentSegmentIndex(int $index): void
    {
        $this->currentSegmentIndex = $index;
    }

    public function setWallClockOffset(float $offset): void
    {
        $this->wallClockOffset = $offset;
    }

    /**
     * @param array<string, mixed> $metrics
     */
    public function setMetrics(array $metrics): void
    {
        $this->metrics = $metrics;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }
}
